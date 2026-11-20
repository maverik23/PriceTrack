<?php

namespace App\Services;

use App\Models\Income;
use App\Models\IncomeSource;
use App\Models\Payment;
use App\Models\RecurringPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Ingresos y pagos: lo recibido en un mes (dinero y en especie, por categoría), lo pagado
 * (recurrentes, únicos y abonos a deudas, por categoría), lo que se espera al mes de los
 * recurrentes, las series de los últimos meses y el balance del mes contra lo gastado (súper,
 * con ReportService::spending(), y pagos registrados).
 */
class IncomeReport
{
    public function __construct(private ReportService $reports) {}

    /** @return array<string, mixed> */
    public function month(CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $until = $from->addMonth();
        $incomes = Income::with('source')->betweenDates($from, $until)->orderByDesc('received_on')->orderByDesc('id')->get();

        $money = (float) $incomes->where('kind', 'money')->sum('amount');
        $inKind = (float) $incomes->where('kind', 'in_kind')->sum('amount');

        // Lo gastado en el mes: súper (lo comprado en las listas, con sus tickets) y pagos
        $super = (float) $this->reports->spending($from, $until->subDay(), 'all')['total'];
        $paid = Payment::with('recurringPayment', 'debt')->betweenDates($from, $until)->orderByDesc('paid_on')->orderByDesc('id')->get();
        $payments = (float) $paid->sum('amount');
        $byKind = fn (string $kind) => (float) $paid->filter(fn (Payment $p) => $p->kind() === $kind)->sum('amount');

        return [
            'month' => $from,
            'incomes' => $incomes,
            'total' => $money + $inKind,
            'money' => $money,
            'inKind' => $inKind,
            'byCategory' => $incomes->groupBy(fn (Income $i) => $i->category ?: 'Sin categoría')
                ->map(fn (Collection $g) => (float) $g->sum('amount'))->sortDesc(),
            'super' => $super,
            'payments' => $payments,
            'paid' => $paid,
            'paymentsRecurring' => $byKind('recurring'),
            'paymentsOneTime' => $byKind('one_time'),
            'paymentsDebt' => $byKind('debt'),
            'paymentsByCategory' => $paid->groupBy(fn (Payment $p) => $p->categoryLabel())
                ->map(fn (Collection $g) => (float) $g->sum('amount'))->sortDesc(),
            'spent' => $super + $payments,
            'balance' => $money + $inKind - $super - $payments,
        ];
    }

    /** Lo que se espera al mes de las recurrentes activas (dinero y en especie). @return array{money: float, in_kind: float, total: float} */
    public function expectedMonthly(): array
    {
        $sources = IncomeSource::active()->get();
        $sum = fn (string $kind) => round($sources->where('kind', $kind)->sum(fn (IncomeSource $s) => $s->monthlyAmount()), 2);

        return ['money' => $sum('money'), 'in_kind' => $sum('in_kind'), 'total' => $sum('money') + $sum('in_kind')];
    }

    /** Lo que se paga al mes en los pagos recurrentes activos. */
    public function expectedPayments(): float
    {
        return round(RecurringPayment::active()->get()->sum(fn (RecurringPayment $r) => $r->monthlyAmount()), 2);
    }

    /**
     * Pagos por mes (los últimos `$months` hasta `$month`): recurrentes y únicos (con los abonos
     * sueltos a deudas).
     *
     * @return Collection<int, object{key: string, label: string, recurring: float, one_time: float, total: float}>
     */
    public function paymentSeries(CarbonImmutable $month, int $months = 12): Collection
    {
        $end = $month->startOfMonth()->addMonth();
        $start = $end->subMonths($months);
        $rows = Payment::betweenDates($start, $end)->get(['paid_on', 'recurring_payment_id', 'amount'])
            ->groupBy(fn (Payment $p) => $p->paid_on->format('Y-m'));

        return collect(range(0, $months - 1))->map(function (int $i) use ($start, $rows) {
            $m = $start->addMonths($i);
            $group = $rows->get($m->format('Y-m'), collect());
            $recurring = (float) $group->whereNotNull('recurring_payment_id')->sum('amount');
            $total = (float) $group->sum('amount');

            return (object) [
                'key' => $m->format('Y-m'),
                'label' => ucfirst($m->translatedFormat('M y')),
                'recurring' => $recurring,
                'one_time' => $total - $recurring,
                'total' => $total,
            ];
        });
    }

    /**
     * Ingresos por mes (los últimos `$months` hasta `$month`): dinero y en especie.
     *
     * @return Collection<int, object{key: string, label: string, money: float, in_kind: float, total: float}>
     */
    public function series(CarbonImmutable $month, int $months = 12): Collection
    {
        $end = $month->startOfMonth()->addMonth();
        $start = $end->subMonths($months);
        $rows = Income::betweenDates($start, $end)->get(['received_on', 'kind', 'amount'])
            ->groupBy(fn (Income $i) => $i->received_on->format('Y-m'));

        return collect(range(0, $months - 1))->map(function (int $i) use ($start, $rows) {
            $m = $start->addMonths($i);
            $group = $rows->get($m->format('Y-m'), collect());
            $money = (float) $group->where('kind', 'money')->sum('amount');
            $inKind = (float) $group->where('kind', 'in_kind')->sum('amount');

            return (object) [
                'key' => $m->format('Y-m'),
                'label' => ucfirst($m->translatedFormat('M y')),
                'money' => $money,
                'in_kind' => $inKind,
                'total' => $money + $inKind,
            ];
        });
    }

    /** Promedio mensual de los meses con registros dentro de la serie (null sin datos). */
    public static function average(Collection $series): ?float
    {
        $first = $series->search(fn ($m) => $m->total > 0);
        if ($first === false) {
            return null;
        }
        $months = $series->slice($first);

        return round($months->sum('total') / $months->count(), 2);
    }
}
