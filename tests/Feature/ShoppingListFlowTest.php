<?php

namespace Tests\Feature;

use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ShoppingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShoppingListFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_flow_list_item_price_and_purchase(): void
    {
        $walmart = Provider::create(['name' => 'Walmart']);
        $sams = Provider::create(['name' => "Sam's Club"]);
        $product = Product::create(['name' => 'Salsa de tomate', 'size' => 345, 'unit' => 'ml']);

        $this->post(route('lists.store'), ['name' => 'Despensa', 'shopped_on' => today()->toDateString()])
            ->assertRedirect();
        $list = ShoppingList::firstOrFail();

        $this->post(route('lists.items.store', $list), ['product_id' => $product->id, 'quantity' => 2])
            ->assertRedirect();
        $item = $list->items()->firstOrFail();
        $this->assertEquals(2, $item->quantity);

        // Precio en Walmart, sin marcar como comprado
        $this->post(route('prices.store'), [
            'item_id' => $item->id, 'provider_id' => $walmart->id, 'price' => 24.50,
            'pack_qty' => 1, 'size' => 345, 'unit' => 'ml', 'observed_on' => today()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Precio en Sam's (3 × 345 ml), marcado como comprado
        $this->post(route('prices.store'), [
            'item_id' => $item->id, 'provider_id' => $sams->id, 'price' => 62.10,
            'pack_qty' => 3, 'size' => 345, 'unit' => 'ml', 'observed_on' => today()->toDateString(), 'select' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $samsPrice = Price::where('provider_id', $sams->id)->firstOrFail();
        $this->assertSame($list->id, $samsPrice->shopping_list_id, 'El precio queda ligado a la lista');
        $this->assertTrue($samsPrice->observed_on->isToday(), 'El precio queda guardado con su fecha');
        $this->assertSame($samsPrice->id, $item->fresh()->price_id);

        $list->load('items.price', 'items.product.prices');
        $this->assertEqualsWithDelta(124.20, $list->total(), 0.001); // 2 × 62.10

        // La hoja recibe los últimos precios por proveedor ya normalizados
        $grid = $this->get(route('lists.show', $list))->assertOk()->viewData('grid');
        $cells = (array) $grid['rows'][0]['cells'];
        $this->assertEqualsWithDelta(0.06, $cells[$sams->id]['unit_price'], 0.000001); // 62.10 / 1035 ml = $60/L
        $this->assertSame('3 × 345 ml', $cells[$sams->id]['presentation']);
        $this->assertTrue($cells[$sams->id]['current']);
        $this->assertSame($samsPrice->id, $grid['rows'][0]['selected']['id']);

        // Elegir Walmart en su lugar
        $walmartPrice = Price::where('provider_id', $walmart->id)->firstOrFail();
        $this->patch(route('lists.items.update', [$list, $item]), ['price_id' => $walmartPrice->id])->assertRedirect();
        $this->assertSame($walmartPrice->id, $item->fresh()->price_id);

        // Desmarcar
        $this->patch(route('lists.items.update', [$list, $item]), ['price_id' => ''])->assertRedirect();
        $this->assertNull($item->fresh()->price_id);
    }

    public function test_estimates_are_normalized_by_unit(): void
    {
        $walmart = Provider::create(['name' => 'Walmart']);
        $costco = Provider::create(['name' => 'Costco']);
        $milk = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);
        $list = ShoppingList::create(['name' => 'L', 'shopped_on' => today()]);
        $item = $list->items()->create(['product_id' => $milk->id, 'quantity' => 2]); // 2 litros

        $base = ['product_id' => $milk->id, 'size' => 1, 'unit' => 'l', 'observed_on' => today()];
        Price::create($base + ['provider_id' => $walmart->id, 'price' => 30.00, 'pack_qty' => 1]);  // 30/L
        Price::create($base + ['provider_id' => $costco->id, 'price' => 150.00, 'pack_qty' => 6]);  // 25/L

        // Estimado: 2 L × 25 $/L, no 2 × paquete de 6
        $this->assertEqualsWithDelta(50.00, $item->fresh()->estimatedSubtotal(), 0.001);

    }

    public function test_cannot_select_a_price_from_another_product(): void
    {
        $provider = Provider::create(['name' => 'Walmart']);
        $a = Product::create(['name' => 'A', 'size' => 1, 'unit' => 'l']);
        $b = Product::create(['name' => 'B', 'size' => 1, 'unit' => 'l']);
        $list = ShoppingList::create(['name' => 'L', 'shopped_on' => today()]);
        $item = $list->items()->create(['product_id' => $a->id, 'quantity' => 1]);
        $otherPrice = Price::create(['product_id' => $b->id, 'provider_id' => $provider->id, 'price' => 10, 'size' => 1, 'unit' => 'l', 'observed_on' => today()]);

        $this->patch(route('lists.items.update', [$list, $item]), ['price_id' => $otherPrice->id])
            ->assertSessionHasErrors('price_id');
    }

    public function test_price_unit_must_match_product_dimension(): void
    {
        $provider = Provider::create(['name' => 'Walmart']);
        $product = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);

        $this->post(route('prices.store'), [
            'product_id' => $product->id, 'provider_id' => $provider->id, 'price' => 30,
            'size' => 1, 'unit' => 'kg', 'observed_on' => today()->toDateString(),
        ])->assertSessionHasErrors('unit');

        // ml sí es compatible con L
        $this->post(route('prices.store'), [
            'product_id' => $product->id, 'provider_id' => $provider->id, 'price' => 30,
            'size' => 946, 'unit' => 'ml', 'observed_on' => today()->toDateString(),
        ])->assertSessionHasNoErrors();
    }

    public function test_redirect_parameter_only_allows_internal_paths(): void
    {
        $provider = Provider::create(['name' => 'Walmart']);
        $product = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);

        $this->post(route('prices.store'), [
            'product_id' => $product->id, 'provider_id' => $provider->id, 'price' => 30,
            'size' => 1, 'unit' => 'l', 'observed_on' => today()->toDateString(), 'redirect' => '//evil.example',
        ])->assertSessionHasErrors('redirect');
    }

    public function test_new_list_can_copy_items_from_previous_list(): void
    {
        $product = Product::create(['name' => 'Pan', 'size' => 680, 'unit' => 'g']);
        $old = ShoppingList::create(['name' => 'Vieja', 'shopped_on' => today()->subWeek()]);
        $old->items()->create(['product_id' => $product->id, 'quantity' => 3]);

        $this->post(route('lists.store'), ['name' => 'Nueva', 'shopped_on' => today()->toDateString(), 'copy_from' => $old->id]);

        $new = ShoppingList::where('name', 'Nueva')->firstOrFail();
        $this->assertSame(1, $new->items()->count());
        $this->assertEquals(3, $new->items()->first()->quantity);
        $this->assertNull($new->items()->first()->price_id);
    }
}
