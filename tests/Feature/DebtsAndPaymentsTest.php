<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Services\FinanceReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebtsAndPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-19 10:00'));
    }

    public function test_balance_without_interest_is_original_minus_payments(): void
    {
        $debt = Debt::create(['name' => 'Préstamo', 'original_amount' => 100000, 'started_on' => '2026-01-01']);
        $debt->payments()->create(['paid_on' => '2026-02-01', 'amount' => 10000]);
        $debt->payments()->create(['paid_on' => '2026-03-01', 'amount' => 10000]);
        $debt->load('payments', 'balances');

        $this->assertEqualsWithDelta(80000, $debt->currentBalance(), 0.01);
        $this->assertEqualsWithDelta(90000, $debt->balanceOn(Carbon::parse('2026-02-15')), 0.01);
        $this->assertSame(0.0, $debt->balanceOn(Carbon::parse('2025-12-31')), 'antes de empezar no se debía');
        $this->assertEqualsWithDelta(20000, $debt->totalPaid(), 0.01);
    }

    public function test_interest_accrues_monthly_and_captures_reset_the_balance(): void
    {
        $debt = Debt::create(['name' => 'Auto', 'original_amount' => 100000, 'annual_rate' => 12, 'started_on' => '2025-11-19']);
        $debt->payments()->create(['paid_on' => '2026-11-19', 'amount' => 10000]);
        $debt->load('payments', 'balances');
        $months = 365 / 30.436875;
        $this->assertEqualsWithDelta(100000 * 1.01 ** $months - 10000, $debt->currentBalance(), 0.05);

        // Una captura del estado de cuenta manda; el pago de ese mismo día ya está incluido
        $this->post(route('debts.balances.store', $debt), ['balance_on' => '2026-11-19', 'balance' => 95000])->assertSessionHasNoErrors();
        $debt->load('balances');
        $this->assertEqualsWithDelta(95000, $debt->currentBalance(), 0.01);

        $debt->update(['paid_off_on' => '2026-11-19']);
        $this->assertSame(0.0, $debt->fresh()->currentBalance());
    }

    public function test_months_left_follows_the_amortization_formula(): void
    {
        // 100,000 al 12 % anual con 2,224.44 al mes = 60 meses (crédito a 5 años)
        $debt = Debt::create(['name' => 'Auto', 'original_amount' => 100000, 'annual_rate' => 12, 'started_on' => '2026-11-19']);
        RecurringPayment::create(['name' => 'Mensualidad', 'amount' => 2224.45, 'next_due_on' => '2026-12-19', 'debt_id' => $debt->id]);
        $this->assertSame(60, $debt->load('recurringPayments', 'payments', 'balances')->monthsLeft());

        $noInterest = Debt::create(['name' => 'Familia', 'original_amount' => 12000, 'started_on' => '2026-11-19']);
        RecurringPayment::create(['name' => 'Abono', 'amount' => 1000, 'next_due_on' => '2026-12-01', 'debt_id' => $noInterest->id]);
        $this->assertSame(12, $noInterest->load('recurringPayments', 'payments', 'balances')->monthsLeft());

        // Si el pago no alcanza para el interés, nunca se termina
        $card = Debt::create(['name' => 'Tarjeta', 'original_amount' => 50000, 'annual_rate' => 60, 'started_on' => '2026-11-19']);
        RecurringPayment::create(['name' => 'Mínimo', 'amount' => 1000, 'next_due_on' => '2026-12-01', 'debt_id' => $card->id]);
        $this->assertNull($card->load('recurringPayments', 'payments', 'balances')->monthsLeft());
    }

    public function test_paying_a_recurring_payment_moves_the_fixed_date_and_lowers_its_debt(): void
    {
        $debt = Debt::create(['name' => 'Hipoteca', 'original_amount' => 500000, 'started_on' => '2026-01-01']);
        $this->post(route('recurring.store'), [
            'name' => 'Mensualidad hipoteca', 'category' => 'Vivienda', 'amount' => 8000, 'every' => 1, 'every_unit' => 'month',
            'next_due_on' => '2026-11-15', 'debt_id' => $debt->id,
        ])->assertSessionHasNoErrors();
        $item = RecurringPayment::firstOrFail();

        // Pagado tarde: la próxima fecha sigue siendo el día 15
        $this->post(route('recurring.pay', $item))->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, '15 dic. 2026'));
        $item->refresh();
        $this->assertSame('2026-12-15', $item->next_due_on->toDateString());
        $payment = Payment::firstOrFail();
        $this->assertSame('2026-11-19', $payment->paid_on->toDateString());
        $this->assertSame('2026-11-15', $payment->due_on->toDateString());
        $this->assertSame($debt->id, $payment->debt_id);
        $this->assertEqualsWithDelta(492000, $debt->load('payments', 'balances')->currentBalance(), 0.01);

        // Otro monto
        $this->post(route('recurring.pay', $item), ['amount' => 8500, 'paid_on' => '2026-11-19']);
        $this->assertSame('2027-01-15', $item->fresh()->next_due_on->toDateString());

        // Deshacer el último regresa la fecha
        $this->delete(route('paid.destroy', Payment::latest('id')->first()))->assertRedirect();
        $this->assertSame('2026-12-15', $item->fresh()->next_due_on->toDateString());
        $this->assertSame(1, Payment::count());

        $this->get(route('incomes.index', ['tab' => 'payments']))->assertOk()->assertSee('Mensualidad hipoteca')->assertSee('$8,000.00');
        $this->get(route('debts.show', $debt))->assertOk()->assertSee('Mensualidad hipoteca');
        $this->get(route('debts.index'))->assertOk()->assertSee('Hipoteca');
    }

    public function test_monthly_amounts_and_month_end_dates(): void
    {
        $yearly = new RecurringPayment(['amount' => 1200, 'every' => 1, 'every_unit' => 'year']);
        $this->assertEqualsWithDelta(100, $yearly->monthlyAmount(), 0.01);
        $bimonthly = new RecurringPayment(['amount' => 600, 'every' => 2, 'every_unit' => 'month']);
        $this->assertEqualsWithDelta(300, $bimonthly->monthlyAmount(), 0.01);
        $weekly = new RecurringPayment(['amount' => 100, 'every' => 1, 'every_unit' => 'week']);
        $this->assertEqualsWithDelta(434.81, $weekly->monthlyAmount(), 0.01);

        $monthly = new RecurringPayment(['every' => 1, 'every_unit' => 'month']);
        $this->assertSame('2027-02-28', $monthly->advance(Carbon::parse('2027-01-31'))->toDateString());
    }

    public function test_net_worth_and_home_include_debts_and_due_payments(): void
    {
        Debt::create(['name' => 'Tarjeta', 'original_amount' => 15000, 'started_on' => '2026-10-01']);
        RecurringPayment::create(['name' => 'Internet', 'amount' => 499, 'next_due_on' => '2026-11-22']);
        RecurringPayment::create(['name' => 'Seguro', 'amount' => 9000, 'every_unit' => 'year', 'next_due_on' => '2027-03-01']);

        $this->assertEqualsWithDelta(-15000, app(FinanceReport::class)->netWorthOn(today())['total'], 0.01);
        $this->get(route('net-worth'))->assertOk()->assertSee('Tarjeta')->assertSee('-$15,000.00');
        $this->get(route('dashboard'))->assertOk()->assertSee('Internet')->assertDontSee('Seguro')->assertSee('Deudas −$15,000');
    }

    public function test_validation_pausing_and_scoping(): void
    {
        $this->post(route('recurring.store'), ['name' => '', 'amount' => 0, 'every' => 0, 'every_unit' => 'decade'])
            ->assertSessionHasErrors(['name', 'amount', 'every', 'every_unit', 'next_due_on']);
        $this->post(route('debts.store'), ['name' => 'X', 'type' => 'nope'])->assertSessionHasErrors(['type', 'original_amount']);

        $item = RecurringPayment::create(['name' => 'Gym', 'amount' => 600, 'next_due_on' => '2026-11-20']);
        $this->get(route('recurring.edit', $item))->assertOk();
        $this->put(route('recurring.update', $item), [
            'name' => 'Gym', 'amount' => 650, 'every' => 1, 'every_unit' => 'month', 'next_due_on' => '2026-11-20', 'active' => '0',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($item->fresh()->active);
        $this->get(route('dashboard'))->assertDontSee('Gym');

        $a = Debt::create(['name' => 'A', 'original_amount' => 10]);
        $b = Debt::create(['name' => 'B', 'original_amount' => 10]);
        $payment = $b->payments()->create(['paid_on' => '2026-11-01', 'amount' => 5]);
        $this->delete(route('debts.payments.destroy', [$a, $payment]))->assertNotFound();
        $this->delete(route('debts.payments.destroy', [$b, $payment]))->assertRedirect();
        $this->delete(route('debts.destroy', $b))->assertRedirect(route('debts.index'));
    }
}
