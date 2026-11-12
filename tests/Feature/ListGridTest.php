<?php

namespace Tests\Feature;

use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ShoppingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoints JSON de la hoja tipo Excel de una lista.
 */
class ListGridTest extends TestCase
{
    use RefreshDatabase;

    private Provider $walmart;

    private Provider $costco;

    private Product $milk;

    private ShoppingList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->walmart = Provider::create(['name' => 'Walmart']);
        $this->costco = Provider::create(['name' => 'Costco']);
        $this->milk = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);
        $this->list = ShoppingList::create(['name' => 'Despensa', 'shopped_on' => '2026-11-10']);
    }

    private function oldPrice(Provider $provider, float $price, string $date = '2026-10-01', int $pack = 1): Price
    {
        return Price::create([
            'product_id' => $this->milk->id, 'provider_id' => $provider->id, 'price' => $price,
            'pack_qty' => $pack, 'size' => 1, 'unit' => 'l', 'observed_on' => $date,
        ]);
    }

    public function test_adding_a_product_returns_its_latest_prices_per_provider(): void
    {
        $this->oldPrice($this->walmart, 25.00, '2026-09-01');
        $latest = $this->oldPrice($this->walmart, 27.00, '2026-10-01');
        $this->oldPrice($this->costco, 150.00, '2026-10-05', 6);

        $res = $this->postJson(route('lists.items.store', $this->list), ['product_id' => $this->milk->id])
            ->assertCreated()
            ->assertJsonPath('existing', false)
            ->assertJsonPath('row.product.name', 'Leche')
            ->assertJsonPath('row.product.label', '1 L');

        $cells = $res->json('row.cells');
        $this->assertSame($latest->id, $cells[$this->walmart->id]['id']);
        $this->assertFalse($cells[$this->walmart->id]['current']);
        $this->assertSame('6 × 1 L', $cells[$this->costco->id]['presentation']);
        $this->assertEqualsWithDelta(0.025, $cells[$this->costco->id]['unit_price'], 0.000001);

        // Elegirlo otra vez no lo duplica ni suma cantidad
        $this->postJson(route('lists.items.store', $this->list), ['product_id' => $this->milk->id])
            ->assertOk()->assertJsonPath('existing', true);
        $this->assertSame(1, $this->list->items()->count());
        $this->assertEquals(1, $this->list->items()->first()->quantity);
    }

    public function test_typing_a_new_product_creates_it_and_adds_it(): void
    {
        $this->postJson(route('lists.items.store', $this->list), ['name' => 'Salsa de tomate', 'size' => 345, 'unit' => 'ml'])
            ->assertCreated()
            ->assertJsonPath('product.label', '345 ml')
            ->assertJsonPath('row.cells', []);

        $this->assertDatabaseHas('products', ['name' => 'Salsa de tomate', 'unit' => 'ml']);
        $this->assertSame(1, $this->list->items()->count());
    }

    public function test_editing_a_cell_registers_a_new_price_for_the_list_date(): void
    {
        $old = $this->oldPrice($this->walmart, 27.00);
        $item = $this->list->items()->create(['product_id' => $this->milk->id, 'quantity' => 1]);
        $url = route('lists.items.prices.store', [$this->list, $item]);

        $res = $this->postJson($url, ['provider_id' => $this->walmart->id, 'price' => 28.50, 'pack_qty' => 1, 'size' => 1, 'unit' => 'l'])
            ->assertOk()
            ->assertJsonPath('price.observed_on', '2026-11-10')
            ->assertJsonPath('price.current', true)
            ->assertJsonPath('price.list_id', $this->list->id);

        $new = Price::findOrFail($res->json('price.id'));
        $this->assertNotSame($old->id, $new->id, 'El precio anterior se conserva en el historial');
        $this->assertSame(2, Price::count());

        // Corregir el mismo día actualiza ese registro, no crea otro
        $this->postJson($url, ['provider_id' => $this->walmart->id, 'price' => 29.00, 'pack_qty' => 1, 'size' => 1, 'unit' => 'l'])
            ->assertOk()->assertJsonPath('price.id', $new->id);
        $this->assertSame(2, Price::count());
        $this->assertEquals(29.00, $new->fresh()->price);

        // Otra presentación compatible (ml) funciona; una incompatible (kg) no
        $this->postJson($url, ['provider_id' => $this->costco->id, 'price' => 54.90, 'pack_qty' => 2, 'size' => 946, 'unit' => 'ml'])
            ->assertOk()->assertJsonPath('price.presentation', '2 × 946 ml');
        $this->postJson($url, ['provider_id' => $this->costco->id, 'price' => 10, 'size' => 1, 'unit' => 'kg'])
            ->assertUnprocessable()->assertJsonValidationErrors('unit');
    }

    public function test_a_future_list_records_prices_with_today(): void
    {
        $future = ShoppingList::create(['name' => 'Próxima', 'shopped_on' => today()->addWeek()]);
        $item = $future->items()->create(['product_id' => $this->milk->id, 'quantity' => 1]);

        $this->postJson(route('lists.items.prices.store', [$future, $item]), ['provider_id' => $this->walmart->id, 'price' => 28, 'size' => 1, 'unit' => 'l'])
            ->assertOk()->assertJsonPath('price.observed_on', today()->toDateString());
    }

    public function test_purchase_follows_the_new_price_of_the_same_provider(): void
    {
        $old = $this->oldPrice($this->walmart, 27.00);
        $item = $this->list->items()->create(['product_id' => $this->milk->id, 'quantity' => 2, 'price_id' => $old->id]);

        $res = $this->postJson(route('lists.items.prices.store', [$this->list, $item]), ['provider_id' => $this->walmart->id, 'price' => 28.50, 'size' => 1, 'unit' => 'l'])
            ->assertOk();
        $this->assertSame($res->json('price.id'), $item->fresh()->price_id);
        $this->assertEquals(28.50, $res->json('item.selected.price'));

        // Un precio en otro proveedor no cambia la compra
        $this->postJson(route('lists.items.prices.store', [$this->list, $item]), ['provider_id' => $this->costco->id, 'price' => 150, 'pack_qty' => 6, 'size' => 1, 'unit' => 'l'])
            ->assertOk();
        $this->assertSame($res->json('price.id'), $item->fresh()->price_id);
    }

    public function test_marking_purchase_and_quantity_as_json(): void
    {
        $price = $this->oldPrice($this->costco, 150.00, '2026-10-05', 6);
        $item = $this->list->items()->create(['product_id' => $this->milk->id, 'quantity' => 1]);
        $url = route('lists.items.update', [$this->list, $item]);

        $this->patchJson($url, ['price_id' => $price->id])->assertOk()
            ->assertJsonPath('item.price_id', $price->id)
            ->assertJsonPath('item.selected.provider_id', $this->costco->id);
        $this->patchJson($url, ['quantity' => 3])->assertOk()->assertJsonPath('item.quantity', 3);
        $this->patchJson($url, ['price_id' => null])->assertOk()->assertJsonPath('item.selected', null);
    }

    public function test_clearing_a_cell_deletes_only_prices_captured_in_this_list(): void
    {
        $old = $this->oldPrice($this->walmart, 27.00);
        $item = $this->list->items()->create(['product_id' => $this->milk->id, 'quantity' => 1]);
        $new = $this->postJson(route('lists.items.prices.store', [$this->list, $item]), ['provider_id' => $this->walmart->id, 'price' => 99, 'size' => 1, 'unit' => 'l'])
            ->json('price.id');

        $this->deleteJson(route('lists.items.prices.destroy', [$this->list, $item, $new]))
            ->assertOk()->assertJsonPath('price.id', $old->id);
        $this->assertDatabaseMissing('prices', ['id' => $new]);

        // Un precio histórico (de otra captura) no se puede borrar desde la hoja
        $this->deleteJson(route('lists.items.prices.destroy', [$this->list, $item, $old]))->assertForbidden();
        $this->assertDatabaseHas('prices', ['id' => $old->id]);
    }

    public function test_removing_a_row_as_json(): void
    {
        $item = $this->list->items()->create(['product_id' => $this->milk->id, 'quantity' => 1]);

        $this->deleteJson(route('lists.items.destroy', [$this->list, $item]))->assertOk();
        $this->assertSame(0, $this->list->items()->count());
    }
}
