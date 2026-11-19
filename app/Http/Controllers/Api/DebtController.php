<?php

namespace App\Http\Controllers\Api;

use App\Api\Present;
use App\Models\Debt;
use App\Models\DebtBalance;
use App\Models\Payment;
use App\Support\DebtData;
use Illuminate\Http\Request;

/** Deudas para la app móvil: saldos capturados y abonos (DebtData). */
class DebtController extends Controller
{
    private const WITH = ['balances', 'payments.recurringPayment', 'recurringPayments'];

    /** Todas (pocas: sin paginar; las vigentes primero, de mayor a menor saldo), con el total y lo que se paga al mes. */
    public function index(Request $request)
    {
        $debts = Debt::with(self::WITH)->orderBy('name')->get()
            ->sortBy(fn (Debt $d) => [$d->isPaidOff() ? 1 : 0, -$d->currentBalance()])->values();
        $detail = $request->boolean('detail');

        return response()->json([
            'data' => $debts->map(fn (Debt $d) => $detail ? Present::debtDetail($d) : Present::debt($d))->values(),
            'total' => round($debts->sum(fn (Debt $d) => $d->currentBalance()), 2),
            'monthly' => round($debts->reject->isPaidOff()->sum(fn (Debt $d) => $d->monthlyPayment()), 2),
            'types' => Debt::TYPES,
        ]);
    }

    public function show(Debt $debt)
    {
        return response()->json(['data' => $this->detail($debt)]);
    }

    public function store(Request $request)
    {
        $debt = Debt::create(DebtData::validate($request->all()));

        return response()->json(['data' => $this->detail($debt), 'message' => 'Deuda guardada. Agrega su pago mensual para llevar el saldo.'], 201);
    }

    /** Solo cambia lo que se manda. */
    public function update(Request $request, Debt $debt)
    {
        $debt->update(DebtData::validate($request->all() + DebtData::toInput($debt)));

        return response()->json(['data' => $this->detail($debt), 'message' => 'Deuda actualizada.']);
    }

    public function destroy(Debt $debt)
    {
        $debt->delete();

        return response()->json(['message' => "Deuda «{$debt->name}» eliminada."]);
    }

    /** `balance_on`, `balance`: el saldo del estado de cuenta (uno por día). */
    public function storeBalance(Request $request, Debt $debt)
    {
        DebtData::saveBalance($debt, $request->all());

        return response()->json(['data' => $this->detail($debt), 'message' => 'Saldo registrado.']);
    }

    public function destroyBalance(Debt $debt, DebtBalance $balance)
    {
        $balance->delete();

        return response()->json(['data' => $this->detail($debt), 'message' => 'Saldo eliminado.']);
    }

    /** `paid_on`, `amount`, `notes?`: un pago suelto o abono a capital. */
    public function storePayment(Request $request, Debt $debt)
    {
        DebtData::savePayment($debt, $request->all());

        return response()->json(['data' => $this->detail($debt), 'message' => 'Pago registrado.'], 201);
    }

    /** Uno de un pago recurrente se deshace (su fecha regresa si era el último). */
    public function destroyPayment(Debt $debt, Payment $payment)
    {
        $payment->recurringPayment ? $payment->recurringPayment->undo($payment) : $payment->delete();

        return response()->json(['data' => $this->detail($debt), 'message' => 'Pago eliminado.']);
    }

    /** @return array<string, mixed> */
    private function detail(Debt $debt): array
    {
        return Present::debtDetail($debt->fresh(self::WITH));
    }
}
