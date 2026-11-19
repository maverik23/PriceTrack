<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Models\DebtBalance;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DebtController extends Controller
{
    public function index()
    {
        $debts = Debt::with(['balances', 'payments', 'recurringPayments'])->orderBy('name')->get()
            ->sortBy(fn (Debt $d) => [$d->isPaidOff() ? 1 : 0, -$d->currentBalance()])->values();

        return view('debts.index', [
            'debts' => $debts,
            'total' => round($debts->sum(fn (Debt $d) => $d->currentBalance()), 2),
            'monthly' => round($debts->reject->isPaidOff()->sum(fn (Debt $d) => $d->monthlyPayment()), 2),
        ]);
    }

    public function create()
    {
        return view('debts.create', ['debt' => new Debt]);
    }

    public function store(Request $request)
    {
        $debt = Debt::create($this->validated($request));

        return redirect()->route('debts.show', $debt)->with('success', 'Deuda guardada. Agrega su pago mensual para llevar el saldo.');
    }

    public function show(Debt $debt)
    {
        $debt->load(['balances', 'payments.recurringPayment', 'recurringPayments']);

        $start = CarbonImmutable::parse(($debt->started_on ?? $debt->balances->first()?->balance_on ?? $debt->payments->first()?->paid_on ?? today())->toDateString());
        $end = $debt->paid_off_on ? CarbonImmutable::parse($debt->paid_off_on->toDateString())->min(today()) : CarbonImmutable::today();
        $step = max(1, (int) ceil($start->diffInMonths($end) / 120));
        $series = [];
        for ($d = $start; $d->lt($end); $d = $d->addMonthsNoOverflow($step)) {
            $series[] = ['x' => $d->toDateString(), 'y' => $debt->balanceOn($d)];
        }
        $series[] = ['x' => $end->toDateString(), 'y' => $debt->balanceOn($end)];

        return view('debts.show', ['debt' => $debt, 'series' => $series]);
    }

    public function edit(Debt $debt)
    {
        return view('debts.edit', ['debt' => $debt]);
    }

    public function update(Request $request, Debt $debt)
    {
        $debt->update($this->validated($request));

        return redirect()->route('debts.show', $debt)->with('success', 'Deuda actualizada.');
    }

    public function destroy(Debt $debt)
    {
        $debt->delete();

        return redirect()->route('debts.index')->with('success', "Deuda «{$debt->name}» eliminada.");
    }

    /** Captura del saldo según el estado de cuenta (una por día). */
    public function storeBalance(Request $request, Debt $debt)
    {
        $data = $request->validate([
            'balance_on' => ['required', 'date', 'before_or_equal:today'],
            'balance' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
        ], [], ['balance_on' => 'fecha', 'balance' => 'saldo']);
        $existing = $debt->balances()->whereDate('balance_on', $data['balance_on'])->first();
        $existing ? $existing->update($data) : $debt->balances()->create($data);

        return redirect()->route('debts.show', $debt)->with('success', 'Saldo registrado.');
    }

    public function destroyBalance(Debt $debt, DebtBalance $balance)
    {
        $balance->delete();

        return redirect()->route('debts.show', $debt)->with('success', 'Saldo eliminado.');
    }

    /** Un pago suelto o un abono a capital (los mensuales se registran desde Pagos). */
    public function storePayment(Request $request, Debt $debt)
    {
        $data = $request->validate([
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['paid_on' => 'fecha', 'amount' => 'monto']);
        $debt->payments()->create($data);

        return redirect()->route('debts.show', $debt)->with('success', 'Pago registrado.');
    }

    public function destroyPayment(Debt $debt, Payment $payment)
    {
        $payment->recurringPayment ? $payment->recurringPayment->undo($payment) : $payment->delete();

        return redirect()->route('debts.show', $debt)->with('success', 'Pago eliminado.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'lender' => ['nullable', 'string', 'max:80'],
            'type' => ['required', Rule::in(array_keys(Debt::TYPES))],
            'original_amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'annual_rate' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'started_on' => ['nullable', 'date', 'before_or_equal:today'],
            'paid_off_on' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], [
            'lender' => 'acreedor', 'type' => 'tipo', 'original_amount' => 'monto original',
            'annual_rate' => 'tasa anual', 'started_on' => 'fecha de inicio', 'paid_off_on' => 'fecha en que se liquidó',
        ]);
    }
}
