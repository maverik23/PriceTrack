<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\InvestmentAccount;
use App\Services\FinanceReport;
use App\Support\Xirr;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-19 10:00'));
    }

    private function d(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    public function test_xirr_matches_known_cases(): void
    {
        // Ejemplo de la documentación de XIRR de Excel: 37.34 %
        $this->assertEqualsWithDelta(0.373362535, Xirr::calculate([
            [$this->d('2008-01-01'), -10000], [$this->d('2008-03-01'), 2750], [$this->d('2008-10-30'), 4250],
            [$this->d('2009-02-15'), 3250], [$this->d('2009-04-01'), 2750],
        ]), 1e-6);

        // 1,000 que en 365 días son 1,100: 10 %
        $this->assertEqualsWithDelta(0.10, Xirr::calculate([[$this->d('2025-01-01'), -1000], [$this->d('2026-01-01'), 1100]]), 1e-9);

        // Pérdida: 1,000 → 500 en dos años (730 días): −29.3 %
        $this->assertEqualsWithDelta(0.5 ** (365 / 730) - 1, Xirr::calculate([[$this->d('2024-01-01'), -1000], [$this->d('2025-12-31'), 500]]), 1e-7);

        // Aportaciones mensuales sin ganancia: 0 %
        $flows = [];
        for ($m = 0; $m < 12; $m++) {
            $flows[] = [$this->d('2025-01-01')->addMonths($m), -100];
        }
        $flows[] = [$this->d('2026-01-01'), 1200];
        $this->assertEqualsWithDelta(0.0, Xirr::calculate($flows), 1e-7);

        // Sin cambio de signo o con un solo flujo no hay tasa
        $this->assertNull(Xirr::calculate([[$this->d('2025-01-01'), -1000]]));
        $this->assertNull(Xirr::calculate([[$this->d('2025-01-01'), -1000], [$this->d('2026-01-01'), 0]]));
        $this->assertNull(Xirr::calculate([[$this->d('2025-01-01'), 1000], [$this->d('2026-01-01'), 1100]]));
    }

    public function test_account_gain_and_return_from_transactions_and_valuations(): void
    {
        $this->post(route('investments.store'), ['name' => 'CETES', 'institution' => 'Cetesdirecto', 'type' => 'cetes', 'currency' => 'mxn'])
            ->assertSessionHasNoErrors();
        $account = InvestmentAccount::firstOrFail();
        $this->assertSame('MXN', $account->currency);

        $this->post(route('investments.transactions.store', $account), ['occurred_on' => '2025-11-19', 'kind' => 'deposit', 'amount' => 10000])->assertSessionHasNoErrors();
        $this->post(route('investments.transactions.store', $account), ['occurred_on' => '2026-05-19', 'kind' => 'income', 'amount' => 450])->assertSessionHasNoErrors();
        $this->post(route('investments.transactions.store', $account), ['occurred_on' => '2026-05-20', 'kind' => 'fee', 'amount' => 20])->assertSessionHasNoErrors();
        $account->load('transactions', 'valuations');
        // Sin capturas vale lo aportado
        $this->assertEqualsWithDelta(10000, $account->currentValue(), 0.01);
        $this->assertEqualsWithDelta(0, $account->gain(), 0.01);

        $this->post(route('investments.valuations.store', $account), ['valued_on' => '2026-11-19', 'value' => 11000])->assertSessionHasNoErrors();
        $account->load('valuations');
        $this->assertEqualsWithDelta(10000, $account->netContributed(), 0.01, 'dividendos y comisiones no son aportaciones');
        $this->assertEqualsWithDelta(1000, $account->gain(), 0.01);
        $this->assertEqualsWithDelta(0.10, $account->simpleReturn(), 1e-9);
        $this->assertEqualsWithDelta(0.10, $account->annualizedReturn(), 1e-6);
        $this->assertEqualsWithDelta(450, $account->totalOf('income'), 0.01);

        // Un retiro reduce lo aportado
        $this->post(route('investments.transactions.store', $account), ['occurred_on' => '2026-11-19', 'kind' => 'withdrawal', 'amount' => 3000]);
        $this->post(route('investments.valuations.store', $account), ['valued_on' => '2026-11-19', 'value' => 8000]);
        $account->load('transactions', 'valuations');
        $this->assertSame(1, $account->valuations->count(), 'una captura por día');
        $this->assertEqualsWithDelta(7000, $account->netContributed(), 0.01);
        $this->assertEqualsWithDelta(1000, $account->gain(), 0.01);

        $this->get(route('investments.index'))->assertOk()->assertSee('CETES')->assertSee('+$1,000.00');
        $this->get(route('investments.show', $account))->assertOk()->assertSee('Valor y aportado');
        $this->get(route('investments.edit', $account))->assertOk();
    }

    public function test_foreign_currency_accounts_report_in_pesos(): void
    {
        $account = InvestmentAccount::create(['name' => 'ETF', 'type' => 'stocks', 'currency' => 'USD']);
        $this->post(route('investments.transactions.store', $account), ['occurred_on' => '2025-11-19', 'kind' => 'deposit', 'amount' => 1000])
            ->assertSessionHasErrors('exchange_rate');
        $this->post(route('investments.transactions.store', $account), ['occurred_on' => '2025-11-19', 'kind' => 'deposit', 'amount' => 1000, 'exchange_rate' => 20])
            ->assertSessionHasNoErrors();
        // Mismos dólares, peso más débil: la ganancia en pesos es por el tipo de cambio
        $this->post(route('investments.valuations.store', $account), ['valued_on' => '2026-11-19', 'value' => 1000, 'exchange_rate' => 22])
            ->assertSessionHasNoErrors();
        $account->load('transactions', 'valuations');

        $this->assertEqualsWithDelta(22000, $account->currentValue(), 0.01);
        $this->assertEqualsWithDelta(2000, $account->gain(), 0.01);
        $this->assertEqualsWithDelta(0.10, $account->annualizedReturn(), 1e-6);

        $this->put(route('investments.update', $account), ['name' => 'ETF', 'type' => 'stocks', 'currency' => 'EUR'])->assertSessionHasErrors('currency');
        $this->get(route('investments.show', $account))->assertOk()->assertSee('USD');
    }

    public function test_closed_accounts_are_worth_nothing_after_closing(): void
    {
        $account = InvestmentAccount::create(['name' => 'Pagaré', 'type' => 'savings', 'closed_on' => '2026-06-01']);
        $account->transactions()->create(['occurred_on' => '2025-06-01', 'kind' => 'deposit', 'amount' => 5000]);
        $account->transactions()->create(['occurred_on' => '2026-06-01', 'kind' => 'withdrawal', 'amount' => 5500]);
        $account->load('transactions', 'valuations');

        $this->assertSame(0.0, $account->currentValue());
        $this->assertEqualsWithDelta(5000, $account->valueOn(Carbon::parse('2026-01-01')), 0.01);
        $this->assertEqualsWithDelta(500, $account->gain(), 0.01);
        $this->assertEqualsWithDelta(0.10, $account->annualizedReturn(), 1e-3);
        $this->assertFalse($account->isStale());
    }

    public function test_net_worth_adds_investments_and_assets(): void
    {
        $account = InvestmentAccount::create(['name' => 'Fondo', 'type' => 'fund']);
        $account->transactions()->create(['occurred_on' => '2025-01-10', 'kind' => 'deposit', 'amount' => 50000]);
        $account->valuations()->create(['valued_on' => '2026-10-01', 'value' => 60000]);
        Asset::create(['name' => 'Auto', 'purchased_on' => '2025-06-01', 'purchase_price' => 200000]);

        $finance = app(FinanceReport::class);
        $now = $finance->netWorthOn(today());
        $this->assertEqualsWithDelta(260000, $now['total'], 0.01);
        $this->assertEqualsWithDelta(50000, $finance->netWorthOn(Carbon::parse('2025-03-01'))['total'], 0.01, 'antes de comprar el auto');

        $series = $finance->netWorthSeries(24);
        $this->assertCount(25, $series);
        $this->assertTrue($series->last()['date']->isToday());
        $this->assertEqualsWithDelta(260000, $series->last()['total'], 0.01);

        $summary = $finance->investmentSummary();
        $this->assertEqualsWithDelta(10000, $summary['gain'], 0.01);
        $this->assertNotNull($summary['annualized']);

        $this->get(route('net-worth'))->assertOk()->assertSee('$260,000.00')->assertSee('Fondo')->assertSee('Auto');
    }

    public function test_validation_and_deleting(): void
    {
        $this->post(route('investments.store'), ['name' => '', 'type' => 'nope', 'currency' => 'PESOS'])
            ->assertSessionHasErrors(['name', 'type', 'currency']);
        $account = InvestmentAccount::create(['name' => 'Ahorro']);
        $this->post(route('investments.transactions.store', $account), ['occurred_on' => '2030-01-01', 'kind' => 'gift', 'amount' => -5])
            ->assertSessionHasErrors(['occurred_on', 'kind', 'amount']);

        $other = InvestmentAccount::create(['name' => 'Otra']);
        $tx = $other->transactions()->create(['occurred_on' => '2026-01-01', 'kind' => 'deposit', 'amount' => 10]);
        $this->delete(route('investments.transactions.destroy', [$account, $tx]))->assertNotFound();
        $this->delete(route('investments.transactions.destroy', [$other, $tx]))->assertRedirect();

        $this->delete(route('investments.destroy', $other))->assertRedirect(route('investments.index'));
        $this->assertSame(1, InvestmentAccount::count());
        $this->get(route('investments.index'))->assertOk()->assertSee('Sin valor capturado');
    }
}
