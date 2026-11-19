<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecurringPaymentController extends Controller
{
    public function index()
    {
        $items = RecurringPayment::with(['debt', 'payments'])->orderBy('next_due_on')->get();
        $active = $items->where('active', true);

        return view('recurring.index', [
            'items' => $active->values(),
            'inactive' => $items->where('active', false)->values(),
            'monthly' => round($active->sum(fn (RecurringPayment $r) => $r->monthlyAmount()), 2),
            'byCategory' => $active->groupBy(fn ($r) => $r->category ?? 'Sin categoría')
                ->map(fn ($group) => round($group->sum(fn ($r) => $r->monthlyAmount()), 2))->sortDesc(),
            'recent' => Payment::with('recurringPayment', 'debt')->orderByDesc('paid_on')->orderByDesc('id')->take(15)->get(),
            'categories' => RecurringPayment::categories(),
            'debts' => Debt::orderBy('name')->get()->reject->isPaidOff(),
            'units' => Task::REPEAT_UNITS,
            'payment' => new RecurringPayment(['next_due_on' => today()]),
        ]);
    }

    public function store(Request $request)
    {
        $item = RecurringPayment::create($this->validated($request));

        return redirect()->route('recurring.index')->with('success', "Pago «{$item->name}» agregado.");
    }

    public function edit(RecurringPayment $recurringPayment)
    {
        return view('recurring.edit', [
            'payment' => $recurringPayment->load('payments'),
            'categories' => RecurringPayment::categories(),
            'debts' => Debt::orderBy('name')->get(),
            'units' => Task::REPEAT_UNITS,
        ]);
    }

    public function update(Request $request, RecurringPayment $recurringPayment)
    {
        $recurringPayment->update($this->validated($request));

        return redirect()->route('recurring.index')->with('success', 'Pago actualizado.');
    }

    public function destroy(RecurringPayment $recurringPayment)
    {
        $recurringPayment->delete();

        return redirect()->route('recurring.index')->with('success', 'Pago eliminado (su historial se conserva).');
    }

    /**
     * Registra el pago del recibo que toca: con el monto de este recibo (vacío = el de siempre)
     * y, si lleva medidor, la lectura final del periodo.
     */
    public function pay(Request $request, RecurringPayment $recurringPayment)
    {
        $data = $request->validate([
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:99999999999'],
            'reading' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['paid_on' => 'fecha', 'amount' => 'monto', 'reading' => 'lectura']);

        $due = $recurringPayment->next_due_on;
        $payment = $recurringPayment->markPaid(
            isset($data['paid_on']) ? CarbonImmutable::parse($data['paid_on']) : null,
            isset($data['amount']) ? (float) $data['amount'] : null,
            $data['notes'] ?? null,
            isset($data['reading']) ? (float) $data['reading'] : null,
        );

        $used = $payment->consumption();
        $detail = $used !== null && $recurringPayment->meter_unit
            ? ' · '.num($used).' '.$recurringPayment->meter_unit.($payment->unitPrice() ? ' a '.money($payment->unitPrice()).'/'.$recurringPayment->meter_unit : '')
            : '';

        return back()->with('success', "«{$recurringPayment->name}» del {$due->translatedFormat('d M')} pagado: ".money($payment->amount)."{$detail}. El siguiente recibo: {$recurringPayment->next_due_on->translatedFormat('d M Y')}.");
    }

    /** Corrige un pago ya registrado (monto, fecha, lectura, notas). */
    public function updatePayment(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'reading' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['paid_on' => 'fecha', 'amount' => 'monto', 'reading' => 'lectura']);
        $payment->update($data);

        return back()->with('success', 'Pago corregido.');
    }

    public function destroyPayment(Payment $payment)
    {
        $payment->recurringPayment ? $payment->recurringPayment->undo($payment) : $payment->delete();

        return back()->with('success', 'Pago eliminado.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'meter_unit' => ['nullable', 'string', 'max:12'],
            'every' => ['required', 'integer', 'min:1', 'max:999'],
            'every_unit' => ['required', Rule::in(array_keys(Task::REPEAT_UNITS))],
            'next_due_on' => ['required', 'date'],
            'debt_id' => ['nullable', 'exists:debts,id'],
            'active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], [
            'category' => 'categoría', 'amount' => 'monto', 'every' => 'cada cuánto', 'every_unit' => 'unidad',
            'next_due_on' => 'próximo pago', 'debt_id' => 'deuda', 'meter_unit' => 'unidad de la lectura',
        ]);
        $data['category'] = RecurringPayment::canonicalCategory($data['category'] ?? null);
        $data['active'] = $request->boolean('active', true);
        $data['meter_unit'] = trim((string) ($data['meter_unit'] ?? '')) ?: null;

        return $data;
    }
}
