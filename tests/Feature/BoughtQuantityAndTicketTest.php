<?php

namespace Tests\Feature;

use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ShoppingList;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoughtQuantityAndTicketTest extends TestCase
{
    use RefreshDatabase;

    private function price(Product $product, Provider $provider, float $price, float $size, string $unit, bool $bulk = false): Price
    {
        return Price::create(['product_id' => $product->id, 'provider_id' => $provider->id, 'price' => $price, 'pack_qty' => 1, 'size' => $size, 'unit' => $unit, 'bulk' => $bulk, 'observed_on' => today()]);
    }

    public function test_bought_quantity_changes_what_was_paid_and_resets_when_changing_store(): void
    {
        $walmart = Provider::create(['name' => 'Walmart']);
        $costco = Provider::create(['name' => 'Costco']);
        $beans = Product::create(['name' => 'Frijol', 'size' => 900, 'unit' => 'g']);
        $atWalmart = $this->price($beans, $walmart, 36.90, 900, 'g');
        $atCostco = $this->price($beans, $costco, 110, 2700, 'g');
        $list = ShoppingList::create(['name' => 'Súper', 'shopped_on' => today()]);
        $item = $list->items()->create(['product_id' => $beans->id, 'quantity' => 2, 'price_id' => $atWalmart->id]);

        // Sin capturar: los paquetes que cubren lo pedido (2 × 900 g)
        $this->assertSame(2.0, $item->fresh()->boughtUnits());
        $this->assertEqualsWithDelta(73.80, $item->fresh()->subtotal(), 0.001);

        // Compré 3 paquetes
        $this->patchJson(route('lists.items.update', [$list, $item]), ['bought_qty' => 3])->assertOk()->assertJsonPath('item.bought_qty', 3);
        $this->assertEqualsWithDelta(110.70, $item->fresh()->subtotal(), 0.001);
        $this->assertEqualsWithDelta(2700, $item->fresh()->boughtBaseQty(), 0.001);

        // Un precio nuevo en la misma tienda lo conserva; otra tienda lo vuelve a calcular
        $newWalmart = $this->price($beans, $walmart, 35, 900, 'g');
        $item->fresh()->followPrice($newWalmart);
        $this->assertSame(3.0, $item->fresh()->bought_qty);
        $item->fresh()->update(['price_id' => $atCostco->id]);
        $this->assertNull($item->fresh()->bought_qty);

        // A granel: lo comprado es en múltiplos del contenido ("por kg")
        $ham = Product::create(['name' => 'Jamón', 'size' => 1, 'unit' => 'kg']);
        $perKg = $this->price($ham, $walmart, 200, 1, 'kg', bulk: true);
        $deli = $list->items()->create(['product_id' => $ham->id, 'quantity' => 0.5, 'price_id' => $perKg->id]);
        $this->assertEqualsWithDelta(100, $deli->fresh()->subtotal(), 0.001);
        $deli->update(['bought_qty' => 0.65]);
        $this->assertEqualsWithDelta(130, $deli->fresh()->subtotal(), 0.001);

        $this->patchJson(route('lists.items.update', [$list, $item]), ['bought_qty' => 0])->assertUnprocessable();
    }

    public function test_ticket_total_is_what_was_paid_in_the_list_and_in_spending(): void
    {
        $walmart = Provider::create(['name' => 'Walmart']);
        $costco = Provider::create(['name' => 'Costco']);
        $milk = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l', 'category' => 'Lácteos']);
        $bread = Product::create(['name' => 'Pan', 'size' => 1, 'unit' => 'item', 'category' => 'Panadería']);
        $list = ShoppingList::create(['name' => 'Súper', 'shopped_on' => today()]);
        $list->items()->create(['product_id' => $milk->id, 'quantity' => 1, 'price_id' => $this->price($milk, $walmart, 60, 1, 'l')->id]);
        $list->items()->create(['product_id' => $bread->id, 'quantity' => 1, 'price_id' => $this->price($bread, $walmart, 40, 1, 'item')->id]);

        $this->assertEqualsWithDelta(100, $list->fresh()->total(), 0.001);

        // Ticket de Walmart con descuento: $90 en lugar de $100
        $this->putJson(route('lists.tickets.update', [$list, $walmart]), ['total' => 90])->assertOk()
            ->assertJsonPath('ticket', 90)->assertJsonPath('total', 90);
        // Ticket de Costco sin artículos marcados ahí
        $this->putJson(route('lists.tickets.update', [$list, $costco]), ['total' => 250])->assertOk()->assertJsonPath('total', 340);

        $paid = $list->fresh()->load('items.price', 'tickets')->paidByProvider();
        $this->assertSame(['computed' => 100.0, 'items' => 2, 'ticket' => 90.0, 'paid' => 90.0], $paid[$walmart->id]);

        // Gastos: el descuento se reparte entre los artículos; el ticket sin artículos cuenta completo
        $spending = app(ReportService::class)->spending(CarbonImmutable::today()->subMonth(), CarbonImmutable::today());
        $this->assertEqualsWithDelta(340, $spending['total'], 0.001);
        $byCategory = $spending['byCategory']->keyBy('name');
        $this->assertEqualsWithDelta(54, $byCategory['Lácteos']->amount, 0.001);
        $this->assertEqualsWithDelta(36, $byCategory['Panadería']->amount, 0.001);
        $this->assertEqualsWithDelta(250, $byCategory['Sin desglose']->amount, 0.001);

        // Vacío lo quita: vuelve la suma de lo marcado
        $this->putJson(route('lists.tickets.update', [$list, $walmart]), ['total' => null])->assertOk()->assertJsonPath('ticket', null);
        $this->assertSame(1, $list->tickets()->count());

        $this->get(route('lists.show', $list))->assertOk()->assertViewHas('grid', fn ($grid) => (array) $grid['tickets'] === [$costco->id => 250.0]);
    }
}
