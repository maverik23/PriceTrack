<?php

namespace Tests\Feature;

use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ShoppingList;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cantidad = lo que se necesita; lo pagado = paquetes completos o lo justo a granel.
 */
class PurchaseQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Provider $walmart;

    private Provider $sams;

    private ShoppingList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->walmart = Provider::create(['name' => 'Walmart']);
        $this->sams = Provider::create(['name' => "Sam's Club"]);
        $this->list = ShoppingList::create(['name' => 'Despensa', 'shopped_on' => today()]);
    }

    private function price(Product $product, Provider $provider, float $price, float $size, string $unit, int $pack = 1, bool $bulk = false): Price
    {
        return Price::create([
            'product_id' => $product->id, 'provider_id' => $provider->id, 'price' => $price,
            'pack_qty' => $pack, 'size' => $size, 'unit' => $unit, 'bulk' => $bulk, 'observed_on' => today(),
        ]);
    }

    public function test_packages_cover_what_you_need(): void
    {
        $yogurt = Product::create(['name' => 'Yogurt', 'size' => 1, 'unit' => 'kg']);
        $walmart = $this->price($yogurt, $this->walmart, 160, 1, 'kg');
        $sams = $this->price($yogurt, $this->sams, 200, 2, 'kg');

        // 1 kg: en Sam's hay que llevarse la bolsa de 2 kg
        $this->assertSame(160.0, $walmart->costFor(1000));
        $this->assertSame(200.0, $sams->costFor(1000));
        $this->assertSame(1, $sams->packsFor(1000));
        $this->assertSame(2, $sams->packsFor(2500));

        // Presentaciones parecidas cuentan como una (946 ml ≈ 1 L); 20 % más ya es otro paquete
        $bottle = $this->price(Product::create(['name' => 'Aceite', 'size' => 1, 'unit' => 'l']), $this->walmart, 50, 946, 'ml');
        $this->assertSame(1, $bottle->packsFor(1000));
        $this->assertSame(2, $bottle->packsFor(1200));
        $liter = $this->price(Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']), $this->walmart, 30, 1, 'l');
        $this->assertSame(1, $liter->packsFor(1150));
        $this->assertSame(2, $liter->packsFor(1151));
        $this->assertSame(7, $liter->packsFor(7000), 'Varias piezas iguales no se redondean hacia abajo');
    }

    public function test_pound_sizes_count_as_their_metric_presentation(): void
    {
        // Queso de 1 kg habitual; Costco vende el bloque de 907 g (2 lb)
        $cheddar = Product::create(['name' => 'Queso Cheddar', 'size' => 1000, 'unit' => 'g']);
        $molida = Product::create(['name' => 'Molida 88/12', 'size' => 2, 'unit' => 'kg']);
        $lomo = Product::create(['name' => 'Cabeza de lomo', 'size' => 1, 'unit' => 'kg']);
        $block = $this->price($cheddar, $this->sams, 107.41, 907, 'g');
        $this->assertSame(1, $block->packsFor(1000));
        $this->assertSame(107.41, $block->costFor(1000));

        $this->list->items()->create(['product_id' => $cheddar->id, 'quantity' => 1, 'price_id' => $block->id]);
        $this->list->items()->create(['product_id' => $molida->id, 'quantity' => 1, 'price_id' => $this->price($molida, $this->sams, 400.27, 2.68, 'kg')->id]);
        $this->list->items()->create(['product_id' => $lomo->id, 'quantity' => 1, 'price_id' => $this->price($lomo, $this->sams, 306.16, 2.32, 'kg')->id]);

        $this->list->load('items.price', 'items.product');
        $this->assertEqualsWithDelta(813.84, $this->list->total(), 0.001);
    }

    public function test_bulk_prices_charge_exactly_what_you_take(): void
    {
        $apple = Product::create(['name' => 'Manzana', 'size' => 1, 'unit' => 'kg']);
        $price = $this->price($apple, $this->walmart, 54, 1, 'kg', pack: 3, bulk: true);

        $this->assertSame(1, $price->pack_qty, 'A granel no hay paquetes');
        $this->assertSame('por kg', $price->presentationLabel());
        $this->assertSame(32.94, $price->costFor(610));

        $item = $this->list->items()->create(['product_id' => $apple->id, 'quantity' => 0.61, 'price_id' => $price->id]);
        $this->assertSame(32.94, $item->fresh()->subtotal());

        $ham = $this->price(Product::create(['name' => 'Jamón', 'size' => 250, 'unit' => 'g']), $this->walmart, 25, 100, 'g', bulk: true);
        $this->assertSame('por 100 g', $ham->presentationLabel());
        $this->assertSame(62.5, $ham->costFor(250));
    }

    public function test_list_total_and_spending_use_what_you_paid(): void
    {
        $yogurt = Product::create(['name' => 'Yogurt', 'size' => 1, 'unit' => 'kg']);
        $apple = Product::create(['name' => 'Manzana', 'size' => 1, 'unit' => 'kg']);
        $sams = $this->price($yogurt, $this->sams, 200, 2, 'kg');
        $bulk = $this->price($apple, $this->walmart, 54, 1, 'kg', bulk: true);

        $this->list->items()->create(['product_id' => $yogurt->id, 'quantity' => 1, 'price_id' => $sams->id]);
        $this->list->items()->create(['product_id' => $apple->id, 'quantity' => 0.61, 'price_id' => $bulk->id]);

        $this->list->load('items.price', 'items.product');
        $this->assertEqualsWithDelta(232.94, $this->list->total(), 0.001);

        $spending = app(ReportService::class)->spending(CarbonImmutable::today()->subMonth(), CarbonImmutable::today()->endOfDay());
        $this->assertEqualsWithDelta(232.94, $spending['total'], 0.001);
    }

    public function test_sheet_saves_bulk_prices(): void
    {
        $apple = Product::create(['name' => 'Manzana', 'size' => 1, 'unit' => 'kg']);
        $item = $this->list->items()->create(['product_id' => $apple->id, 'quantity' => 0.61]);

        $this->postJson(route('lists.items.prices.store', [$this->list, $item]), [
            'provider_id' => $this->walmart->id, 'price' => 54, 'pack_qty' => 1, 'size' => 1, 'unit' => 'kg', 'bulk' => true,
        ])->assertOk()
            ->assertJsonPath('price.bulk', true)
            ->assertJsonPath('price.presentation', 'por kg');

        $this->patchJson(route('lists.items.update', [$this->list, $item]), ['quantity' => 0.6618])
            ->assertOk()->assertJsonPath('item.quantity', 0.6618);
    }

    public function test_price_form_saves_bulk_prices(): void
    {
        $apple = Product::create(['name' => 'Manzana', 'size' => 1, 'unit' => 'kg']);

        $this->post(route('prices.store'), [
            'product_id' => $apple->id, 'provider_id' => $this->walmart->id, 'price' => 54,
            'pack_qty' => 1, 'size' => 1, 'unit' => 'kg', 'bulk' => '1', 'observed_on' => today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Price::firstOrFail()->bulk);
    }

    public function test_migration_keeps_the_totals_of_existing_purchases(): void
    {
        $milk = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);
        $apple = Product::create(['name' => 'Manzana', 'size' => 1, 'unit' => 'kg']);
        $bread = Product::create(['name' => 'Pan', 'size' => 680, 'unit' => 'g']);
        $costco = $this->price($milk, $this->sams, 134.50, 1, 'l', pack: 6);
        $apples = $this->price($apple, $this->walmart, 54, 1, 'kg');
        $loaf = $this->price($bread, $this->walmart, 56.20, 680, 'g');

        // Como quedaban antes: cantidad = paquetes del precio elegido
        $rows = [
            [$milk, $costco, 2],    // 2 paquetes de 6 L = $269
            [$apple, $apples, 0.61], // 0.61 del paquete de 1 kg = $32.94
            [$bread, $loaf, 2],     // 2 panes = $112.40
        ];
        foreach ($rows as [$product, $price, $quantity]) {
            DB::table('shopping_list_items')->insert([
                'shopping_list_id' => $this->list->id, 'product_id' => $product->id,
                'quantity' => $quantity, 'price_id' => $price->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $migration = require database_path('migrations/2026_11_14_000001_add_bulk_prices_and_need_quantities.php');
        $migration->convertQuantities();

        $items = $this->list->items()->with('price', 'product')->orderBy('id')->get();
        $this->assertEquals([12, 0.61, 2], $items->pluck('quantity')->all()); // 12 L de leche, 610 g, 2 panes
        $this->assertEquals([269.0, 32.94, 112.4], $items->map->subtotal()->all());
        $this->assertTrue($apples->fresh()->bulk, 'Una cantidad fraccionaria era una compra a granel');
        $this->assertFalse($costco->fresh()->bulk);
    }

    public function test_spending_averages_cover_at_least_a_week_and_explain_what_is_missing(): void
    {
        $milk = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);
        $price = $this->price($milk, $this->walmart, 70, 1, 'l');
        $this->list->items()->create(['product_id' => $milk->id, 'quantity' => 1, 'price_id' => $price->id]);
        $this->list->items()->create(['product_id' => Product::create(['name' => 'Pan', 'size' => 1, 'unit' => 'item'])->id, 'quantity' => 1]);
        $future = ShoppingList::create(['name' => 'Mañana', 'shopped_on' => today()->addDay()]);
        $future->items()->create(['product_id' => $milk->id, 'quantity' => 1, 'price_id' => $price->id]);

        $response = $this->get(route('reports.spending'))->assertOk();

        $this->assertEqualsWithDelta(70, $response->viewData('weekly'), 0.001); // y no 7 × 70
        $this->assertSame(1, $response->viewData('span'));
        $this->assertSame(['unmarked' => 1, 'future' => 1], $response->viewData('gaps'));
        $response->assertSee('sin marcar como comprado')->assertSee('fecha futura');
    }
}
