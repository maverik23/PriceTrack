<?php

namespace App\Http\Controllers\Api;

use App\Api\Present;
use App\Models\InvestmentAccount;
use App\Models\InvestmentTransaction;
use App\Models\InvestmentValuation;
use App\Services\FinanceReport;
use App\Support\InvestmentAccountData;
use Illuminate\Http\Request;

/** Inversiones para la app móvil: cuentas, movimientos y valores (InvestmentAccountData, FinanceReport). */
class InvestmentController extends Controller
{
    /**
     * Todas las cuentas (pocas: sin paginar; las abiertas primero, de mayor a menor valor) y el
     * resumen: valor, aportado, ganancia y rendimiento anual de todas juntas. `detail=1` = fichas.
     */
    public function index(Request $request, FinanceReport $finance)
    {
        $accounts = $finance->accounts();
        $summary = $finance->investmentSummary($accounts);
        $detail = $request->boolean('detail');

        return response()->json([
            'data' => $accounts->sortBy(fn (InvestmentAccount $a) => [$a->closed_on ? 1 : 0, -$a->currentValue()])
                ->map(fn (InvestmentAccount $a) => $detail ? Present::investmentDetail($a) : Present::investment($a))->values(),
            'summary' => [
                'value' => $summary['value'],
                'contributed' => $summary['contributed'],
                'gain' => $summary['gain'],
                'annualized_return' => $summary['annualized'] === null ? null : round($summary['annualized'], 4),
                'stale' => $summary['stale']->map(fn (InvestmentAccount $a) => ['id' => $a->id, 'name' => $a->name])->values(),
            ],
        ]);
    }

    /** Tipos de cuenta, instituciones ya usadas y tipos de movimiento. */
    public function options()
    {
        return response()->json([
            'types' => InvestmentAccount::TYPES,
            'institutions' => InvestmentAccount::institutions(),
            'kinds' => InvestmentTransaction::KINDS,
        ]);
    }

    public function show(InvestmentAccount $investment)
    {
        return response()->json(['data' => $this->detail($investment)]);
    }

    public function store(Request $request)
    {
        $account = InvestmentAccount::create(InvestmentAccountData::validate($request->all()));

        return response()->json(['data' => $this->detail($account), 'message' => 'Cuenta guardada. Registra tus aportaciones y su valor de hoy.'], 201);
    }

    /** Solo cambia lo que se manda (la moneda no cambia si ya hay registros). */
    public function update(Request $request, InvestmentAccount $investment)
    {
        $investment->update(InvestmentAccountData::validate($request->all() + InvestmentAccountData::toInput($investment), $investment));

        return response()->json(['data' => $this->detail($investment), 'message' => 'Cuenta actualizada.']);
    }

    public function destroy(InvestmentAccount $investment)
    {
        $investment->delete();

        return response()->json(['message' => "Cuenta «{$investment->name}» eliminada."]);
    }

    /** `occurred_on`, `kind` (deposit, withdrawal, income, fee), `amount`, `exchange_rate` (si no es MXN), `notes?`. */
    public function storeTransaction(Request $request, InvestmentAccount $investment)
    {
        $transaction = InvestmentAccountData::saveTransaction($investment, $request->all());

        return response()->json(['data' => $this->detail($investment), 'message' => $transaction->kindLabel().' registrada.'], 201);
    }

    public function destroyTransaction(InvestmentAccount $investment, InvestmentTransaction $transaction)
    {
        $transaction->delete();

        return response()->json(['data' => $this->detail($investment), 'message' => 'Movimiento eliminado.']);
    }

    /** `valued_on`, `value` (en la moneda de la cuenta), `exchange_rate` (si no es MXN): uno por día. */
    public function storeValuation(Request $request, InvestmentAccount $investment)
    {
        InvestmentAccountData::saveValuation($investment, $request->all());

        return response()->json(['data' => $this->detail($investment), 'message' => 'Valor registrado.']);
    }

    public function destroyValuation(InvestmentAccount $investment, InvestmentValuation $valuation)
    {
        $valuation->delete();

        return response()->json(['data' => $this->detail($investment), 'message' => 'Valor eliminado.']);
    }

    /** @return array<string, mixed> */
    private function detail(InvestmentAccount $account): array
    {
        return Present::investmentDetail($account->fresh(['transactions', 'valuations']));
    }
}
