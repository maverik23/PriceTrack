<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Support\RecurringPaymentData;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/** Pagos recurrentes (se listan en la pestaña Pagos de IncomeController): alta, ficha, pagar y corregir. */
class RecurringPaymentController extends Controller
{
    public function store(Request $request)
    {
        $item = RecurringPayment::create($this->validated($request));

        return redirect()->route('incomes.index', ['tab' => 'payments'])->with('success', "Pago «{$item->name}» agregado.");
    }

    public function edit(RecurringPayment $recurringPayment)
    {
        return view('recurring.edit', [
            'payment' => $recurringPayment->load('payments'),
            'categories' => RecurringPayment::categories(),
            'debts' => Debt::orderBy('name')->get(),
            'units' => RecurringPayment::scheduleUnits(true),
        ]);
    }

    public function update(Request $request, RecurringPayment $recurringPayment)
    {
        $recurringPayment->update($this->validated($request));

        return redirect()->route('incomes.index', ['tab' => 'payments'])->with('success', 'Pago actualizado.');
    }

    public function destroy(RecurringPayment $recurringPayment)
    {
        $recurringPayment->delete();

        return redirect()->route('incomes.index', ['tab' => 'payments'])->with('success', 'Pago eliminado (su historial se conserva).');
    }

    /**
     * Registra el pago del recibo que toca: con el monto de este recibo (vacío = el de siempre)
     * y, si lleva medidor, la lectura final del periodo.
     */
    public function pay(Request $request, RecurringPayment $recurringPayment)
    {
        $due = $recurringPayment->next_due_on;
        $payment = RecurringPaymentData::pay($recurringPayment, $request->all());

        return back()->with('success', self::paidMessage($recurringPayment, $payment, $due));
    }

    /** "«Luz» del 02 nov pagado: $450.00 · 210 kWh a $2.14/kWh. El siguiente recibo: 02 ene 2027." */
    public static function paidMessage(RecurringPayment $recurring, Payment $payment, CarbonInterface $due): string
    {
        $used = $payment->consumption();
        $detail = $used !== null && $recurring->meter_unit
            ? ' · '.num($used).' '.$recurring->meter_unit.($payment->unitPrice() ? ' a '.money($payment->unitPrice()).'/'.$recurring->meter_unit : '')
            : '';

        return "«{$recurring->name}» del {$due->translatedFormat('d M')} pagado: ".money($payment->amount)."{$detail}. El siguiente recibo: {$recurring->next_due_on->translatedFormat('d M Y')}.";
    }

    /** Corrige un pago ya registrado (monto, fecha, lectura, notas). */
    public function updatePayment(Request $request, Payment $payment)
    {
        RecurringPaymentData::correct($payment, $request->all());

        return back()->with('success', 'Pago corregido.');
    }

    public function destroyPayment(Payment $payment)
    {
        $payment->recurringPayment ? $payment->recurringPayment->undo($payment) : $payment->delete();

        return back()->with('success', 'Pago eliminado.');
    }

    private function validated(Request $request): array
    {
        return RecurringPaymentData::validate($request->all());
    }
}
