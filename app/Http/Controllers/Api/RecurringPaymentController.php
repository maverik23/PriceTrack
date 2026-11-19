<?php

namespace App\Http\Controllers\Api;

use App\Api\Present;
use App\Http\Controllers\RecurringPaymentController as WebRecurring;
use App\Models\Debt;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Support\Paginate;
use App\Support\RecurringPaymentData;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Pagos recurrentes para la app móvil: pagar con monto y lectura, deshacer y corregir (RecurringPaymentData). */
class RecurringPaymentController extends Controller
{
    /**
     * Los activos (paginados, por próxima fecha; `q` busca en nombre, categoría, notas y deuda)
     * y, completos, los pausados. Los totales (al mes, por categoría) cuentan todos los activos.
     */
    public function index(Request $request)
    {
        $items = RecurringPayment::with(['debt', 'payments'])->orderBy('next_due_on')->get();
        $active = $items->where('active', true);
        $search = Str::of($request->query('q'))->trim()->ascii()->lower()->toString();
        $matches = fn (RecurringPayment $r) => $search === ''
            || Str::of(implode(' ', [$r->name, $r->category, $r->notes, $r->debt?->name]))->ascii()->lower()->contains($search);
        $detail = $request->boolean('detail');
        $present = fn (RecurringPayment $r) => $detail ? Present::recurringDetail($r) : Present::recurring($r);

        return $this->page(Paginate::collection($active->filter($matches)->values(), $request, $this->perPage($request)), $present, [
            'inactive' => $items->where('active', false)->filter($matches)->map($present)->values(),
            'monthly' => round($active->sum(fn (RecurringPayment $r) => $r->monthlyAmount()), 2),
            'by_category' => $active->groupBy(fn ($r) => $r->category ?? 'Sin categoría')
                ->map(fn ($group) => round($group->sum(fn ($r) => $r->monthlyAmount()), 2))->sortDesc(),
            'recent' => Payment::with('recurringPayment', 'debt')->orderByDesc('paid_on')->orderByDesc('id')->take(15)->get()
                ->map(fn (Payment $p) => Present::payment($p))->values(),
        ]);
    }

    /** Categorías ya usadas, unidades (con quincena) y deudas vigentes, para el formulario. */
    public function options()
    {
        return response()->json([
            'categories' => RecurringPayment::categories(),
            'units' => self::units(RecurringPayment::scheduleUnits(true)),
            'debts' => Debt::with(['balances', 'payments'])->orderBy('name')->get()->reject->isPaidOff()
                ->map(fn (Debt $d) => ['id' => $d->id, 'name' => $d->name])->values(),
        ]);
    }

    public function show(RecurringPayment $recurringPayment)
    {
        return response()->json(['data' => $this->detail($recurringPayment)]);
    }

    public function store(Request $request)
    {
        $item = RecurringPayment::create(RecurringPaymentData::validate($request->all()));

        return response()->json(['data' => $this->detail($item), 'message' => "Pago «{$item->name}» agregado."], 201);
    }

    /** Solo cambia lo que se manda (`active: false` lo pausa). */
    public function update(Request $request, RecurringPayment $recurringPayment)
    {
        $recurringPayment->update(RecurringPaymentData::validate($request->all() + RecurringPaymentData::toInput($recurringPayment)));

        return response()->json(['data' => $this->detail($recurringPayment), 'message' => 'Pago actualizado.']);
    }

    public function destroy(RecurringPayment $recurringPayment)
    {
        $recurringPayment->delete();

        return response()->json(['message' => 'Pago eliminado (su historial se conserva).']);
    }

    /**
     * Paga el recibo que toca: `amount?` (vacío = el de referencia), `paid_on?`, `reading?`
     * (lectura final; si el pago aún no tiene unidad, con `meter_unit`), `notes?`.
     */
    public function pay(Request $request, RecurringPayment $recurringPayment)
    {
        $due = $recurringPayment->next_due_on;
        $payment = RecurringPaymentData::pay($recurringPayment, $request->all());

        return response()->json([
            'data' => $this->detail($recurringPayment),
            'payment_id' => $payment->id,
            'message' => WebRecurring::paidMessage($recurringPayment, $payment, $due),
        ], 201);
    }

    /** Corrige un pago (solo cambia lo que se manda: `paid_on`, `amount`, `reading`, `notes`). */
    public function updatePayment(Request $request, Payment $payment)
    {
        RecurringPaymentData::correct($payment, $request->all() + RecurringPaymentData::paymentInput($payment));

        return response()->json(['data' => Present::payment($payment->fresh()), 'message' => 'Pago corregido.']);
    }

    /** Deshace un pago: si era el último de su pago recurrente, la fecha regresa a la que tocaba. */
    public function destroyPayment(Payment $payment)
    {
        $payment->recurringPayment ? $payment->recurringPayment->undo($payment) : $payment->delete();

        return response()->json(['message' => 'Pago eliminado.']);
    }

    /** @return array<string, mixed> */
    private function detail(RecurringPayment $recurring): array
    {
        return Present::recurringDetail($recurring->fresh(['debt', 'payments']));
    }
}
