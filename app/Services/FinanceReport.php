<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\InvestmentAccount;
use App\Support\Xirr;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Inversiones y patrimonio neto (inversiones + bienes), en pesos. */
class FinanceReport
{
    public function __construct(private AssetReport $assets) {}

    /** @return Collection<int, InvestmentAccount> */
    public function accounts(): Collection
    {
        return InvestmentAccount::with(['transactions', 'valuations'])->orderBy('name')->get();
    }

    public function investmentsOn(CarbonInterface $date, ?Collection $accounts = null): float
    {
        return round(($accounts ?? $this->accounts())->sum(fn (InvestmentAccount $a) => $a->valueOn($date)), 2);
    }

    /**
     * Totales de las cuentas abiertas y el rendimiento de todas juntas (XIRR con todos
     * los flujos y el valor de hoy).
     *
     * @return array{value: float, contributed: float, gain: float, annualized: ?float, stale: Collection}
     */
    public function investmentSummary(?Collection $accounts = null): array
    {
        $accounts ??= $this->accounts();
        $today = CarbonImmutable::today();
        $value = $this->investmentsOn($today, $accounts);
        $contributed = round($accounts->sum(fn (InvestmentAccount $a) => $a->netContributed($today)), 2);

        $flows = $accounts->flatMap(fn (InvestmentAccount $a) => $a->transactions
            ->filter(fn ($t) => $t->contribution() != 0)
            ->map(fn ($t) => [CarbonImmutable::parse($t->occurred_on->toDateString()), -$t->contribution()]))
            ->values()->all();
        $flows[] = [$today, $value];

        return [
            'value' => $value,
            'contributed' => $contributed,
            'gain' => round($value - $contributed, 2),
            'annualized' => Xirr::calculate($flows),
            'stale' => $accounts->filter->isStale()->values(),
        ];
    }

    /** @return Collection<int, Debt> */
    public function debts(): Collection
    {
        return Debt::with(['balances', 'payments'])->orderBy('name')->get();
    }

    public function debtsOn(CarbonInterface $date, ?Collection $debts = null): float
    {
        return round(($debts ?? $this->debts())->sum(fn (Debt $d) => $d->balanceOn($date)), 2);
    }

    /** Patrimonio neto = inversiones + bienes − deudas. */
    public function netWorthOn(CarbonInterface $date, ?Collection $accounts = null, ?Collection $assets = null, ?Collection $debts = null): array
    {
        $investments = $this->investmentsOn($date, $accounts);
        $assetsValue = $this->assets->totalValueOn($date, $assets);
        $debtsValue = $this->debtsOn($date, $debts);

        return [
            'investments' => $investments,
            'assets' => $assetsValue,
            'debts' => $debtsValue,
            'total' => round($investments + $assetsValue - $debtsValue, 2),
        ];
    }

    /**
     * Patrimonio al cierre de cada mes de los últimos $months meses (el último punto es hoy).
     *
     * @return Collection<int, array{date: CarbonImmutable, investments: float, assets: float, debts: float, total: float}>
     */
    public function netWorthSeries(int $months = 24): Collection
    {
        $accounts = $this->accounts();
        $assets = $this->assets->assets();
        $debts = $this->debts();
        $today = CarbonImmutable::today();

        return collect(range($months, 0))
            ->map(fn (int $back) => $back === 0 ? $today : $today->subMonthsNoOverflow($back)->endOfMonth()->startOfDay())
            ->map(fn (CarbonImmutable $date) => ['date' => $date, ...$this->netWorthOn($date, $accounts, $assets, $debts)]);
    }
}
