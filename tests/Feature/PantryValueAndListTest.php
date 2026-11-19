<?php

namespace Tests\Feature;

use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ShoppingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PantryValueAndListTest extends TestCase
{
    use RefreshDatabase;

    public function test_pantry_value_uses_what_is_left_and_the_cheapest_unit_price(): void
    {
        $provider = Provider::create(['name' => 'Súper']);
        $rice = Product::create(['name' => 'Arroz', 'size' => 1, 'unit' => 'kg']);
        $oil = Product::create(['name' => 'Aceite', 'size' => 1, 'unit' => 'l']);
        $salt = Product::create(['name' => 'Sal', 'size' => 1, 'unit' => 'kg']);
        Price::create(['product_id' => $rice->id, 'provider_id' => $provider->id, 'price' => 40, 'pack_qty' => 1, 'size' => 1, 'unit' => 'kg', 'observed_on' => today()]);
        Price::create(['product_id' => $oil->id, 'provider_id' => $provider->id, 'price' => 50, 'pack_qty' => 1, 'size' => 1, 'unit' => 'l', 'observed_on' => today()]);

        $rice->setPantry(true, 250);   // 250 g × $0.04/g = $10
        $oil->setPantry(true);         // "suficiente": una botella de 1 L = $50, estimado
        $salt->setPantry(true, 500);   // sin precio: no cuenta

        $this->assertSame(['value' => 10.0, 'estimated' => false], $rice->fresh()->pantryValue());
        $this->assertSame(['value' => 50.0, 'estimated' => true], $oil->fresh()->pantryValue());
        $this->assertNull($salt->fresh()->pantryValue());

        $rows = collect($this->get(route('pantry.index'))->assertOk()->assertSee('Valor de la despensa')->viewData('rows'))->keyBy('name');
        $this->assertEquals(10, $rows['Arroz']['value']);
        $this->assertTrue($rows['Aceite']['estimated']);
        $this->assertNull($rows['Sal']['value']);
    }

    public function test_something_that_ran_out_can_be_added_to_a_shopping_list(): void
    {
        $milk = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);
        $milk->setPantry(true);
        $list = ShoppingList::create(['name' => 'Súper del sábado', 'shopped_on' => today()]);

        // Se acaba: la respuesta dice que no está en ninguna lista
        $this->postJson(route('products.pantry', $milk), ['have' => false])->assertOk()
            ->assertJsonPath('row.pantry', 'out')->assertJsonPath('row.in_list', null);

        $this->postJson(route('products.pantry.list', $milk), ['list_id' => $list->id])->assertOk()
            ->assertJsonPath('list.id', $list->id)->assertJsonPath('row.in_list', 'Súper del sábado');
        $this->assertSame(1, $list->items()->where('product_id', $milk->id)->count());

        // Otra vez: no se duplica
        $this->postJson(route('products.pantry.list', $milk), ['list_id' => $list->id])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'ya estaba'));
        $this->assertSame(1, $list->items()->count());

        // Sin lista: se crea una nueva
        $eggs = Product::create(['name' => 'Huevo', 'size' => 30, 'unit' => 'item']);
        $this->postJson(route('products.pantry.list', $eggs))->assertOk();
        $this->assertSame(2, ShoppingList::count());

        // Las listas ya compradas no se ofrecen
        $list->markDone();
        $this->assertSame([ShoppingList::latest('id')->first()->id], array_column($this->get(route('pantry.index'))->viewData('lists'), 'id'));
    }
}
