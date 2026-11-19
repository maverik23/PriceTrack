<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Models\DebtBalance;
use App\Models\Payment;
use App\Support\DebtData;
use Illuminate\Http\Request;

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

        return view('debts.show', ['debt' => $debt, 'series' => $debt->balanceSeries()]);
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
        DebtData::saveBalance($debt, $request->all());

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
        DebtData::savePayment($debt, $request->all());

        return redirect()->route('debts.show', $debt)->with('success', 'Pago registrado.');
    }

    public function destroyPayment(Debt $debt, Payment $payment)
    {
        $payment->recurringPayment ? $payment->recurringPayment->undo($payment) : $payment->delete();

        return redirect()->route('debts.show', $debt)->with('success', 'Pago eliminado.');
    }

    private function validated(Request $request): array
    {
        return DebtData::validate($request->all());
    }
}
