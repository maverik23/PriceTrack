<?php

namespace App\Http\Controllers;

use App\Models\InvestmentAccount;
use App\Models\InvestmentTransaction;
use App\Services\FinanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $request->merge(['currency' => strtoupper(trim((string) $request->input('currency', 'MXN'))) ?: 'MXN']);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'institution' => ['nullable', 'string', 'max:80'],
            'type' => ['required', Rule::in(array_keys(InvestmentAccount::TYPES))],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'notes' => ['nullable', 'string', 'max:255'],
            'closed_on' => ['nullable', 'date', 'before_or_equal:today'],
        ], [], ['institution' => 'institución', 'type' => 'tipo', 'currency' => 'moneda', 'closed_on' => 'fecha de cierre']);

        // Cambiar la moneda con registros dejaría los tipos de cambio sin sentido
        if ($account && $account->currency !== $data['currency']
            && ($account->transactions()->exists() || $account->valuations()->exists())) {
            throw ValidationException::withMessages(['currency' => 'La cuenta ya tiene registros en '.$account->currency.'; crea otra cuenta para otra moneda.']);
        }

        return $data;
    }
}
