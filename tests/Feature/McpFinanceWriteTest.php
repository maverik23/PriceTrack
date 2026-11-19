<?php

namespace Tests\Feature;

use App\Mcp\PriceTrackServer;
use App\Mcp\Tools\GetHelp;
use App\Mcp\Tools\RecordDebt;
use App\Mcp\Tools\RecordInvestment;
use App\Mcp\Tools\SaveIncomeSource;
use App\Mcp\Tools\SaveRecurringPayment;
use App\Models\Debt;
use App\Models\IncomeSource;
use App\Models\InvestmentAccount;
use App\Models\RecurringPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpFinanceWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_recurring_payments_and_income_sources_are_created_and_changed(): void
    {
        PriceTrackServer::tool(SaveRecurringPayment::class, ['name' => 'Agua', 'amount' => 300, 'next_due_on' => '2026-12-05', 'meter_unit' => 'm³', 'category' => 'servicios'])->assertOk();
        $water = RecurringPayment::firstOrFail();
        $this->assertSame(['m³', 'month', 1], [$water->meter_unit, $water->every_unit, $water->every]);

        PriceTrackServer::tool(SaveRecurringPayment::class, ['id' => $water->id, 'amount' => 350, 'active' => false])->assertOk();
        $this->assertSame([350.0, false, 'Agua'], [$water->fresh()->amount, $water->fresh()->active, $water->fresh()->name]);
        PriceTrackServer::tool(SaveRecurringPayment::class, ['name' => 'Luz'])->assertHasErrors(['monto']);

        PriceTrackServer::tool(SaveIncomeSource::class, ['name' => 'Sueldo', 'amount' => 18000, 'next_on' => '2026-11-30', 'every_unit' => 'fortnight'])->assertOk()->assertSee('Cada quincena');
        $this->assertSame(36000.0, IncomeSource::firstOrFail()->monthlyAmount());
    }

    public function test_investments_and_debts_get_movements_values_and_balances(): void
    {
        $account = InvestmentAccount::create(['name' => 'Cetes', 'type' => 'other', 'currency' => 'MXN']);
        PriceTrackServer::tool(RecordInvestment::class, ['account_id' => $account->id, 'type' => 'deposit', 'amount' => 10000, 'date' => '2026-01-10'])->assertOk();
        PriceTrackServer::tool(RecordInvestment::class, ['account_id' => $account->id, 'type' => 'valuation', 'amount' => 10800])->assertOk()->assertSee('10800');
        $this->assertSame([1, 1], [$account->transactions()->count(), $account->valuations()->count()]);

        $usd = InvestmentAccount::create(['name' => 'Broker', 'type' => 'other', 'currency' => 'USD']);
        PriceTrackServer::tool(RecordInvestment::class, ['account_id' => $usd->id, 'type' => 'deposit', 'amount' => 500])->assertHasErrors(['exchange_rate']);

        $debt = Debt::create(['name' => 'Tarjeta', 'type' => 'card', 'original_amount' => 20000, 'annual_rate' => 0, 'started_on' => '2026-01-01']);
        PriceTrackServer::tool(RecordDebt::class, ['debt_id' => $debt->id, 'type' => 'balance', 'amount' => 15000, 'date' => '2026-11-01'])->assertOk();
        PriceTrackServer::tool(RecordDebt::class, ['debt_id' => $debt->id, 'type' => 'payment', 'amount' => 5000])->assertOk();
        $this->assertEqualsWithDelta(10000, $debt->fresh()->currentBalance(), 0.01);
    }

    public function test_help_explains_every_section_and_the_page_lists_the_tools(): void
    {
        PriceTrackServer::tool(GetHelp::class, [])->assertOk()
            ->assertSee('get-finances')->assertSee('record-investment')->assertSee('Cómo se conecta')->assertSee('Cocina');
        PriceTrackServer::tool(GetHelp::class, ['section' => 'finanzas'])->assertOk()->assertSee('save-recurring-payment')->assertDontSee('plan-meal');

        $this->get(route('assistant'))->assertOk()->assertSee('Asistente (MCP)')->assertSee('record-debt')->assertSee(url('/mcp'));
    }
}
