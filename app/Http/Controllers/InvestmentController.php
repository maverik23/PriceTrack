<?php

namespace App\Http\Controllers;

use App\Models\InvestmentAccount;
use App\Models\InvestmentTransaction;
use App\Services\FinanceReport;
use App\Support\InvestmentAccountData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class InvestmentController extends Controller
{
    public function index(FinanceReport $finance)
    {
        $accounts = $finance->accounts();

        return view('investments.index', [
            'accounts' => $accounts->sortBy(fn (InvestmentAccount $a) => [$a->closed_on ? 1 : 0, -$a->currentValue()])->values(),
            'summary' => $finance->investmentSummary($accounts),
        ]);
    }

    public function create()
    {
        return view('investments.create', $this->formData(new InvestmentAccount));
    }

    public function store(Request $request)
    {
        $account = InvestmentAccount::create($this->validated($request));

        return redirect()->route('investments.show', $account)->with('success', 'Cuenta guardada. Registra tus aportaciones y su valor de hoy.');
    }

    public function show(InvestmentAccount $investment)
    {
        $investment->load(['transactions', 'valuations']);

        // Valor y aportado neto en cada captura y movimiento, para la gráfica
        $dates = $investment->valuations->pluck('valued_on')
            ->merge($investment->transactions->pluck('occurred_on'))
            ->push(today())
            ->map(fn ($d) => CarbonImmutable::parse($d->toDateString()))
            ->unique(fn ($d) => $d->toDateString())->sort()->values();

        return view('investments.show', [
            'account' => $investment,
            'kinds' => InvestmentTransaction::KINDS,
            'series' => [
                ['label' => 'Valor', 'data' => $dates->map(fn ($d) => ['x' => $d->toDateString(), 'y' => $investment->valueOn($d)])->all(), 'stepped' => true],
                ['label' => 'Aportado neto', 'data' => $dates->map(fn ($d) => ['x' => $d->toDateString(), 'y' => $investment->netContributed($d)])->all(), 'stepped' => true, 'borderDash' => [5, 4]],
            ],
        ]);
    }

    public function edit(InvestmentAccount $investment)
    {
        return view('investments.edit', $this->formData($investment));
    }

    public function update(Request $request, InvestmentAccount $investment)
    {
        $investment->update($this->validated($request, $investment));

        return redirect()->route('investments.show', $investment)->with('success', 'Cuenta actualizada.');
    }

    public function destroy(InvestmentAccount $investment)
    {
        $investment->delete();

        return redirect()->route('investments.index')->with('success', "Cuenta «{$investment->name}» eliminada.");
    }

    private function formData(InvestmentAccount $account): array
    {
        return ['account' => $account, 'institutions' => InvestmentAccount::institutions()];
    }

    private function validated(Request $request, ?InvestmentAccount $account = null): array
    {
        return InvestmentAccountData::validate($request->all(), $account);
    }
}
