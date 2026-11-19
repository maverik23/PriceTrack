<?php

namespace Tests\Feature;

use App\Mcp\PriceTrackServer;
use App\Mcp\Tools\CreateProduct;
use App\Mcp\Tools\GetSpendingReport;
use App\Mcp\Tools\RenameCategory;
use App\Mcp\Tools\SaveProvider;
use App\Mcp\Tools\SaveRecurringPayment;
use App\Models\InvestmentAccount;
use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\RecurringPayment;
use App\Models\ShoppingList;
use App\Services\FinanceReport;
use App\Services\IncomeReport;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Lo que reportó quien usa el MCP: categorías, promedio del súper, rendimiento total y quincenas. */
class McpFeedbackFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 12:00:00');
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function buy(Provider $provider, string $date, float $price): void
    {
        $product = Product::firstOrCreate(['name' => 'Cosa '.$provider->id], ['size' => 1, 'unit' => 'item']);
        $p = Price::create(['product_id' => $product->id, 'provider_id' => $provider->id, 'price' => $price, 'pack_qty' => 1, 'size' => 1, 'unit' => 'item', 'observed_on' => $date]);
        ShoppingList::create(['name' => "Compra $date", 'shopped_on' => $date])->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price_id' => $p->id]);
    }

    public function test_categories_warn_when_reused_and_can_be_renamed(): void
    {
        Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l', 'category' => 'Lacteos']);
        Product::create(['name' => 'Yogur', 'size' => 1, 'unit' => 'kg', 'category' => 'lácteos']);

        PriceTrackServer::tool(CreateProduct::class, ['name' => 'Queso', 'size' => 400, 'unit' => 'g', 'category' => 'Lácteos'])
            ->assertOk()->assertSee('notices')->assertSee('rename-category');

        PriceTrackServer::tool(RenameCategory::class, ['kind' => 'product_category'])->assertOk()->assertSee('Lacteos');
        PriceTrackServer::tool(RenameCategory::class, ['kind' => 'product_category', 'from' => 'lacteos', 'to' => 'Lácteos'])->assertOk();
        $this->assertSame(['Lácteos'], Product::pluck('category')->unique()->values()->all());

        PriceTrackServer::tool(RenameCategory::class, ['kind' => 'product_category', 'from' => 'Repostería', 'to' => 'Postres'])->assertHasErrors(['No existe']);
        // Ya con la escritura buena no hay aviso
        PriceTrackServer::tool(CreateProduct::class, ['name' => 'Crema', 'size' => 1, 'unit' => 'l', 'category' => 'Lácteos'])->assertOk()->assertDontSee('notices');
    }

    public function test_spending_leaves_other_stores_out_and_does_not_project_a_week(): void
    {
        $walmart = Provider::create(['name' => 'Walmart']);
        PriceTrackServer::tool(SaveProvider::class, ['name' => 'Home Depot'])->assertOk();
        $depot = Provider::where('name', 'Home Depot')->firstOrFail();
        $this->assertFalse($depot->groceries, 'Se adivina por el nombre');

        $this->buy($walmart, '2026-11-13', 1000);
        $this->buy($depot, '2026-11-14', 5000);

        $reports = app(ReportService::class);
        $super = $reports->spending(CarbonImmutable::parse('2025-11-20'), CarbonImmutable::today()->endOfDay());
        $this->assertEqualsWithDelta(1000, $super['total'], 0.01);
        $this->assertSame(['Home Depot'], $super['excluded']['stores']);
        $this->assertTrue($super['shortMonthly']);
        $this->assertEqualsWithDelta(6000, $reports->spending(CarbonImmutable::parse('2025-11-20'), CarbonImmutable::today()->endOfDay(), 'all')['total'], 0.01);
        $this->assertEqualsWithDelta(5000, $reports->spending(CarbonImmutable::parse('2025-11-20'), CarbonImmutable::today()->endOfDay(), 'other')['total'], 0.01);

        PriceTrackServer::tool(GetSpendingReport::class)->assertOk()
            ->assertSee('projection')->assertSee('warning')->assertSee('other_stores');

        // El balance de ingresos cuenta todo lo gastado
        $this->assertEqualsWithDelta(6000, app(IncomeReport::class)->month(CarbonImmutable::parse('2026-11-01'))['super'], 0.01);

        $this->get(route('reports.spending'))->assertOk()->assertSee('Home Depot')->assertSee('proyectado');
        $this->get(route('reports.spending', ['stores' => 'all']))->assertOk();
    }

    public function test_total_investment_return_matches_the_only_account(): void
    {
        $account = InvestmentAccount::create(['name' => 'Fondo', 'type' => 'fund']);
        $account->transactions()->create(['occurred_on' => '2025-01-10', 'kind' => 'deposit', 'amount' => 50000]);
        $account->transactions()->create(['occurred_on' => '2025-08-10', 'kind' => 'deposit', 'amount' => 10000]);
        // La última captura es de hace mes y medio: la cuenta se mide a esa fecha
        $account->valuations()->create(['valued_on' => '2026-10-01', 'value' => 66000]);

        $summary = app(FinanceReport::class)->investmentSummary();
        $this->assertEqualsWithDelta($account->fresh(['transactions', 'valuations'])->annualizedReturn(), $summary['annualized'], 1e-9);
        $this->assertEqualsWithDelta(6000, $summary['gain'], 0.01);
    }

    public function test_recurring_payments_accept_fortnights(): void
    {
        PriceTrackServer::tool(SaveRecurringPayment::class, ['name' => 'Gasolina', 'amount' => 800, 'every' => 1, 'every_unit' => 'fortnight', 'next_due_on' => '2026-11-30'])->assertOk()->assertSee('Cada quincena');
        $payment = RecurringPayment::firstOrFail();
        $this->assertEqualsWithDelta(1600, $payment->monthlyAmount(), 0.01);
        $payment->markPaid(CarbonImmutable::parse('2026-11-30'));
        $this->assertSame('2026-12-15', $payment->fresh()->next_due_on->toDateString());

        $this->post(route('recurring.store'), ['name' => 'Vales', 'amount' => 500, 'every' => 1, 'every_unit' => 'fortnight', 'next_due_on' => '2026-11-30'])->assertSessionHasNoErrors();
        $this->get(route('recurring.index'))->assertOk()->assertSee('quincena');
    }
}
