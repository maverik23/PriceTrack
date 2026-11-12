<?php

namespace Tests\Feature;

use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ShoppingList;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private function price(Product $product, Provider $provider, float $price, string $date, int $pack = 1): Price
    {
        return Price::create([
            'product_id' => $product->id, 'provider_id' => $provider->id, 'price' => $price,
            'pack_qty' => $pack, 'size' => $product->size, 'unit' => $product->unit, 'observed_on' => $date,
        ]);
    }

    public function test_spending_averages_use_daily_rate(): void
    {
        CarbonImmutable::setTestNow('2026-03-31');
        $provider = Provider::create(['name' => 'Walmart']);
        $product = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l', 'category' => 'Lácteos']);

        // 3 compras de $100 entre el 1 de enero y el 31 de marzo (90 días)
        foreach (['2026-01-01', '2026-02-01', '2026-03-31'] as $date) {
            $list = ShoppingList::create(['name' => $date, 'shopped_on' => $date]);
            $price = $this->price($product, $provider, 50, $date);
            $list->items()->create(['product_id' => $product->id, 'quantity' => 2, 'price_id' => $price->id]);
        }
        // Un artículo sin comprar no cuenta
        $pending = ShoppingList::create(['name' => 'pendiente', 'shopped_on' => '2026-03-15']);
        $pending->items()->create(['product_id' => $product->id, 'quantity' => 5]);

        $r = (new ReportService)->spending(CarbonImmutable::parse('2025-04-01'), CarbonImmutable::parse('2026-03-31')->endOfDay());

        $this->assertEqualsWithDelta(300, $r['total'], 0.001);
        $this->assertSame(90, $r['days']);
        $this->assertEqualsWithDelta(300 / 90 * 7, $r['weekly'], 0.001);
        $this->assertEqualsWithDelta(300 / 90 * 365.25 / 12, $r['monthly'], 0.001);
        $this->assertEqualsWithDelta(300 / 90 * 365.25, $r['annual'], 0.001);
        $this->assertSame(3, $r['lists']);
        $this->assertCount(3, $r['byMonth']);
        $this->assertSame('Walmart', $r['byProvider']->first()->name);
        $this->assertSame('Lácteos', $r['byCategory']->first()->name);

        CarbonImmutable::setTestNow();
    }

    public function test_inflation_and_price_changes(): void
    {
        $walmart = Provider::create(['name' => 'Walmart']);
        $costco = Provider::create(['name' => 'Costco']);
        $milk = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);
        $rice = Product::create(['name' => 'Arroz', 'size' => 1, 'unit' => 'kg']);

        // Leche +10% en Walmart; en Costco cambia presentación pero no precio unitario
        $this->price($milk, $walmart, 20.00, '2026-01-10');
        $this->price($milk, $walmart, 22.00, '2026-02-10');
        $this->price($milk, $costco, 120.00, '2026-01-10', 6); // 20/L
        $this->price($milk, $costco, 20.00, '2026-02-10', 1);  // 20/L → sin cambio
        // Arroz -10%
        $this->price($rice, $walmart, 30.00, '2026-01-10');
        $this->price($rice, $walmart, 27.00, '2026-02-10');

        $from = CarbonImmutable::parse('2026-01-01');
        $to = CarbonImmutable::parse('2026-02-28')->endOfDay();
        $service = new ReportService;

        $changes = $service->priceChanges($from, $to);
        $this->assertCount(2, $changes, 'El cambio de presentación sin cambio de precio unitario no cuenta');
        $this->assertEqualsWithDelta(0.10, $changes->firstWhere('product.name', 'Leche')->change, 0.0001);
        $this->assertEqualsWithDelta(-0.10, $changes->firstWhere('product.name', 'Arroz')->change, 0.0001);

        $inflation = $service->inflation($from, $to);
        // Índice: media geométrica de (1.10, 1.00, 0.90) ≈ 0.99666
        $this->assertEqualsWithDelta(pow(1.10 * 1.00 * 0.90, 1 / 3) - 1, $inflation['overall'], 0.0001);
        $this->assertSame(1, $inflation['up']);
        $this->assertSame(1, $inflation['down']);
        $milkRow = $inflation['products']->firstWhere('product.name', 'Leche');
        $this->assertEqualsWithDelta(0.05, $milkRow->change, 0.0001); // promedio de +10% y 0%
    }

    public function test_all_pages_render_with_demo_data(): void
    {
        $this->seed(DemoDataSeeder::class);

        $product = Product::firstOrFail();
        $list = ShoppingList::latest('id')->firstOrFail();
        $price = Price::firstOrFail();
        $provider = Provider::firstOrFail();

        $pages = [
            route('dashboard'),
            route('providers.index'),
            route('providers.edit', $provider),
            route('products.index'),
            route('products.index', ['q' => 'leche']),
            route('products.create'),
            route('products.show', $product),
            route('products.edit', $product),
            route('prices.index'),
            route('prices.create', ['product_id' => $product->id]),
            route('prices.edit', $price),
            route('lists.index'),
            route('lists.create'),
            route('lists.show', $list),
            route('lists.edit', $list),
            route('reports.spending'),
            route('reports.inflation'),
            route('reports.changes'),
            route('reports.inflation', ['from' => now()->subYears(5)->toDateString(), 'to' => now()->toDateString()]),
        ];

        foreach ($pages as $url) {
            $this->get($url)->assertOk();
        }
    }
}
