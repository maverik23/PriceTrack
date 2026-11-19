<?php

namespace App\Http\Controllers;

use App\Models\InvestmentAccount;
use App\Models\InvestmentTransaction;
use App\Models\InvestmentValuation;
use App\Support\InvestmentAccountData;
use Illuminate\Http\Request;

/** Movimientos y valores de una cuenta de inversión. */
class InvestmentRecordController extends Controller
{
    public function storeTransaction(Request $request, InvestmentAccount $investment)
    {
        $transaction = InvestmentAccountData::saveTransaction($investment, $request->all());

        return $this->back($investment, $transaction->kindLabel().' registrada.');
    }

    public function destroyTransaction(InvestmentAccount $investment, InvestmentTransaction $transaction)
    {
        $transaction->delete();

        return $this->back($investment, 'Movimiento eliminado.');
    }

    public function storeValuation(Request $request, InvestmentAccount $investment)
    {
        InvestmentAccountData::saveValuation($investment, $request->all());

        return $this->back($investment, 'Valor registrado.');
    }

    public function destroyValuation(InvestmentAccount $investment, InvestmentValuation $valuation)
    {
        $valuation->delete();

        return $this->back($investment, 'Valor eliminado.');
    }

    private function back(InvestmentAccount $account, string $message)
    {
        return redirect()->route('investments.show', $account)->with('success', $message);
    }
}
