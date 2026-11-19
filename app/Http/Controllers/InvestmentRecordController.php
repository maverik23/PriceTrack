<?php

namespace App\Http\Controllers;

use App\Models\InvestmentAccount;
use App\Models\InvestmentTransaction;
use App\Models\InvestmentValuation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Movimientos y valores de una cuenta de inversión. */
class InvestmentRecordController extends Controller
{
    public function storeTransaction(Request $request, InvestmentAccount $investment)
    {
        $data = $request->validate([
            'occurred_on' => ['required', 'date', 'before_or_equal:today'],
            'kind' => ['required', Rule::in(array_keys(InvestmentTransaction::KINDS))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'exchange_rate' => $this->rateRules($investment),
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['occurred_on' => 'fecha', 'kind' => 'tipo de movimiento', 'amount' => 'monto', 'exchange_rate' => 'tipo de cambio']);
        $data['exchange_rate'] = $investment->isForeign() ? $data['exchange_rate'] : 1;
        $investment->transactions()->create($data);

        return $this->back($investment, InvestmentTransaction::KINDS[$data['kind']].' registrada.');
    }

    public function destroyTransaction(InvestmentAccount $investment, InvestmentTransaction $transaction)
    {
        $transaction->delete();

        return $this->back($investment, 'Movimiento eliminado.');
    }

    public function storeValuation(Request $request, InvestmentAccount $investment)
    {
        $data = $request->validate([
            'valued_on' => ['required', 'date', 'before_or_equal:today'],
            'value' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'exchange_rate' => $this->rateRules($investment),
        ], [], ['valued_on' => 'fecha', 'value' => 'valor', 'exchange_rate' => 'tipo de cambio']);
        $data['exchange_rate'] = $investment->isForeign() ? $data['exchange_rate'] : 1;

        // Una captura por día: corrige la de ese día si ya había
        $existing = $investment->valuations()->whereDate('valued_on', $data['valued_on'])->first();
        $existing ? $existing->update($data) : $investment->valuations()->create($data);

        return $this->back($investment, 'Valor registrado.');
    }

    public function destroyValuation(InvestmentAccount $investment, InvestmentValuation $valuation)
    {
        $valuation->delete();

        return $this->back($investment, 'Valor eliminado.');
    }

    private function rateRules(InvestmentAccount $account): array
    {
        return $account->isForeign() ? ['required', 'numeric', 'gt:0', 'max:1000000'] : ['nullable'];
    }

    private function back(InvestmentAccount $account, string $message)
    {
        return redirect()->route('investments.show', $account)->with('success', $message);
    }
}
