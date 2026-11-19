<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\Income;
use App\Models\InvestmentAccount;
use App\Models\Payment;
use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\RecurringPayment;
use App\Models\ShoppingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** API de la app móvil: Finanzas (inversiones, patrimonio, deudas, pagos, ingresos) y reportes del súper. */
class ApiFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_investments_records_and_summary(): void
    {
        $id = $this->postJson('/api/v1/investments', ['name' => 'CETES', 'type' => 'cetes', 'institution' => 'Cetesdirecto'])
            ->assertCreated()->assertJsonPath('data.currency', 'MXN')->json('data.id');
        $this->postJson('/api/v1/investments', ['name' => 'X', 'type' => 'nada'])->assertStatus(422)->assertJsonValidationErrors('type');

        $this->postJson("/api/v1/investments/{$id}/transactions", ['occurred_on' => today()->subYear()->toDateString(), 'kind' => 'deposit', 'amount' => 10000])
            ->assertCreated()->assertJsonPath('message', 'Aportación registrada.')->assertJsonPath('data.contributed', 10000);
        $valued = $this->postJson("/api/v1/investments/{$id}/valuations", ['valued_on' => today()->toDateString(), 'value' => 11000])->assertOk()->json('data');
        $this->assertEquals(11000, $valued['value']);
        $this->assertEquals(1000, $valued['gain']);
        $this->assertEqualsWithDelta(0.1, $valued['annualized_return'], 0.002);
        $this->assertSame('Valor', $valued['series'][0]['label']);

        // Solo cambia lo que se manda; la moneda no cambia con registros
        $this->putJson("/api/v1/investments/{$id}", ['notes' => 'A 28 días'])->assertOk()->assertJsonPath('data.name', 'CETES');
        $this->putJson("/api/v1/investments/{$id}", ['currency' => 'USD'])->assertStatus(422)->assertJsonValidationErrors('currency');

        // Una cuenta en dólares pide tipo de cambio
        $usd = InvestmentAccount::create(['name' => 'ETF', 'type' => 'stocks', 'currency' => 'USD']);
        $this->postJson("/api/v1/investments/{$usd->id}/valuations", ['valued_on' => today()->toDateString(), 'value' => 100])->assertStatus(422)->assertJsonValidationErrors('exchange_rate');

        $this->getJson('/api/v1/investments')->assertOk()
            ->assertJsonPath('data.0.name', 'CETES')
            ->assertJsonPath('summary.value', 11000)
            ->assertJsonPath('summary.stale.0.name', 'ETF');
        $this->getJson('/api/v1/investments/options')->assertOk()->assertJsonPath('kinds.fee', 'Comisión');

        $tx = $valued['transactions'][0]['id'];
        $this->deleteJson("/api/v1/investments/{$usd->id}/transactions/{$tx}")->assertNotFound();
        $this->deleteJson("/api/v1/investments/{$id}/transactions/{$tx}")->assertOk()->assertJsonCount(0, 'data.transactions');
        $this->deleteJson("/api/v1/investments/{$id}")->assertOk();
        $this->assertNull(InvestmentAccount::find($id));
    }

    public function test_debts_balances_payments_and_net_worth(): void
    {
        $id = $this->postJson('/api/v1/debts', ['name' => 'Auto', 'type' => 'car', 'original_amount' => 100000, 'started_on' => today()->subMonths(3)->toDateString()])
            ->assertCreated()->assertJsonPath('data.balance', 100000)->json('data.id');
        $this->putJson("/api/v1/debts/{$id}", ['lender' => 'Banco'])->assertOk()->assertJsonPath('data.original_amount', 100000);

        $this->postJson("/api/v1/debts/{$id}/balances", ['balance_on' => today()->subMonth()->toDateString(), 'balance' => 90000])->assertOk()
            ->assertJsonPath('data.balance', 90000);
        $payment = $this->postJson("/api/v1/debts/{$id}/payments", ['paid_on' => today()->toDateString(), 'amount' => 5000])->assertCreated()
            ->assertJsonPath('data.balance', 85000)->json('data.payments.0.id');
        $this->assertNotEmpty($this->getJson("/api/v1/debts/{$id}")->json('data.series'));
        $this->getJson('/api/v1/debts')->assertOk()->assertJsonPath('total', 85000)->assertJsonPath('types.car', 'Crédito del auto');

        $worth = $this->getJson('/api/v1/net-worth?months=12')->assertOk()->json('data');
        $this->assertEquals(-85000, $worth['now']['total']);
        $this->assertCount(13, $worth['series']);
        $this->assertSame('Auto', $worth['debts'][0]['name']);

        $this->deleteJson("/api/v1/debts/{$id}/payments/{$payment}")->assertOk()->assertJsonPath('data.balance', 90000);
        $this->deleteJson("/api/v1/debts/{$id}")->assertOk();
        $this->assertSame(0, Debt::count());
    }

    public function test_recurring_payments_pay_with_reading_undo_and_correct(): void
    {
        $id = $this->postJson('/api/v1/recurring', ['name' => 'Luz', 'amount' => 400, 'every' => 2, 'every_unit' => 'month', 'next_due_on' => today()->toDateString()])
            ->assertCreated()->assertJsonPath('data.active', true)->assertJsonPath('data.asks_reading', true)
            ->assertJsonPath('data.suggested_meter_unit', 'kWh')->json('data.id');

        // La primera lectura trae su unidad
        $this->postJson("/api/v1/recurring/{$id}/pay", ['amount' => 450, 'reading' => 1000])->assertStatus(422)->assertJsonValidationErrors('meter_unit');
        $this->postJson("/api/v1/recurring/{$id}/pay", ['amount' => 450, 'reading' => 1000, 'meter_unit' => 'kWh'])->assertCreated()
            ->assertJsonPath('data.meter_unit', 'kWh')->assertJsonPath('data.amount', 450)
            ->assertJsonPath('data.next_due_on', today()->addMonthsNoOverflow(2)->toDateString());
        $second = $this->postJson("/api/v1/recurring/{$id}/pay", ['amount' => 420, 'reading' => 1210])->assertCreated()
            ->assertJsonPath('data.last_payment.consumption', 210)
            ->assertJsonPath('data.last_payment.unit_price', 2)
            ->assertJsonCount(2, 'data.history')->json('payment_id');

        // Corregir: solo cambia lo que se manda
        $this->putJson("/api/v1/paid/{$second}", ['amount' => 441])->assertOk()->assertJsonPath('data.amount', 441)->assertJsonPath('data.reading', 1210);
        $this->getJson("/api/v1/recurring/{$id}")->assertOk()->assertJsonPath('data.history.0.unit_price', 2.1);

        // Deshacer el último regresa la fecha
        $this->deleteJson("/api/v1/paid/{$second}")->assertOk();
        $this->assertSame(today()->addMonthsNoOverflow(2)->toDateString(), RecurringPayment::find($id)->next_due_on->toDateString());

        // Pausar (solo cambia lo que se manda) y la lista
        $this->putJson("/api/v1/recurring/{$id}", ['active' => false])->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.name', 'Luz');
        $this->getJson('/api/v1/recurring')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('inactive.0.name', 'Luz')->assertJsonPath('recent.0.amount', 450);
        $this->getJson('/api/v1/recurring/options')->assertOk()->assertJsonPath('units.fortnight.one', 'quincena');

        $this->deleteJson("/api/v1/recurring/{$id}")->assertOk();
        $this->assertSame(1, Payment::count());
    }

    public function test_incomes_month_sources_and_receive(): void
    {
        $source = $this->postJson('/api/v1/income-sources', ['name' => 'Sueldo', 'kind' => 'money', 'amount' => 20000, 'every' => 1, 'every_unit' => 'fortnight', 'next_on' => today()->toDateString()])
            ->assertCreated()->assertJsonPath('data.monthly', 40000)->json('data.id');
        $this->putJson("/api/v1/income-sources/{$source}", ['category' => 'Sueldo'])->assertOk()->assertJsonPath('data.amount', 20000);

        $received = $this->postJson("/api/v1/income-sources/{$source}/receive")->assertCreated()
            ->assertJsonPath('income.amount', 20000)->json('income.id');
        $this->postJson('/api/v1/incomes', ['name' => 'Vales', 'received_on' => today()->toDateString(), 'amount' => 1500, 'kind' => 'in_kind'])->assertCreated();

        $month = $this->getJson('/api/v1/incomes')->assertOk()->json('data');
        $this->assertSame(today()->format('Y-m'), $month['month']);
        $this->assertEquals(21500, $month['total']);
        $this->assertEquals(1500, $month['in_kind']);
        $this->assertEquals(21500, $month['balance']);
        $this->assertCount(12, $month['series']);
        $this->assertEquals(40000, $month['expected']['money']);
        $this->assertSame('Sueldo', $month['sources'][0]['name']);

        // Deshacer lo recibido regresa la fecha de la recurrente
        $this->deleteJson("/api/v1/incomes/{$received}")->assertOk();
        $this->assertSame(today()->toDateString(), $this->getJson('/api/v1/incomes')->json('data.sources.0.next_on'));
        $this->deleteJson("/api/v1/income-sources/{$source}")->assertOk();
        $this->assertSame(1, Income::count());
    }

    public function test_grocery_reports(): void
    {
        $walmart = Provider::create(['name' => 'Walmart']);
        $milk = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l', 'category' => 'Lácteos']);
        $old = Price::create(['product_id' => $milk->id, 'provider_id' => $walmart->id, 'price' => 20, 'pack_qty' => 1, 'size' => 1, 'unit' => 'l', 'observed_on' => today()->subMonths(2)]);
        $new = Price::create(['product_id' => $milk->id, 'provider_id' => $walmart->id, 'price' => 22, 'pack_qty' => 1, 'size' => 1, 'unit' => 'l', 'observed_on' => today()->subDay()]);
        // Ayer: en SQLite la fecha se guarda con hora y el último día del rango no entra (ver AGENTS.md)
        $list = ShoppingList::create(['name' => 'Despensa', 'shopped_on' => today()->subDay()]);
        $list->items()->create(['product_id' => $milk->id, 'quantity' => 2, 'price_id' => $new->id]);

        $this->getJson('/api/v1/reports/spending?stores=all')->assertOk()
            ->assertJsonPath('data.total', 44)
            ->assertJsonPath('data.by_provider.0.name', 'Walmart')
            ->assertJsonPath('data.by_category.0.name', 'Lácteos')
            ->assertJsonPath('data.short_monthly', true);
        $this->getJson('/api/v1/reports/inflation')->assertOk()
            ->assertJsonPath('data.products.0.change', 0.1)
            ->assertJsonPath('data.products.0.providers.0.provider', 'Walmart');
        $this->getJson('/api/v1/reports/changes')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.change', 0.1)->assertJsonPath('up', 1)->assertJsonPath('meta.total', 1);
        $this->assertNotNull($old);
    }
}
