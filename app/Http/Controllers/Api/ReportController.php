<?php

namespace App\Http\Controllers\Api;

use App\Api\Present;
use App\Models\Asset;
use App\Models\Debt;
use App\Models\InvestmentAccount;
use App\Models\MaintenancePlan;
use App\Services\AssetReport;
use App\Services\FinanceReport;
use App\Services\ReportService;
use App\Support\Paginate;
use Illuminate\Http\Request;

/** Reportes para la app móvil (los mismos servicios que las pantallas de reportes). */
class ReportController extends Controller
{
    /** Mantenimiento por bien y por año, costo de tener cada bien, planes próximos y garantías por vencer. */
    public function assets(AssetReport $report)
    {
        $assets = $report->assets();
        $table = $report->maintenanceByYear($assets);

        return response()->json(['data' => [
            'years' => $table['years'],
            'rows' => $table['rows']->map(fn (array $row) => [
                'asset_id' => $row['asset']->id,
                'name' => $row['asset']->name,
                'status' => $row['asset']->status,
                'by_year' => $row['byYear'],
                'total' => $row['total'],
                'value' => $row['value'],
                'gain' => $row['gain'],
                'ownership' => $row['ownership'],
            ])->values()->all(),
            'totals' => $table['totals'],
            'summary' => AssetController::summary($report->summary($assets)),
            'plans' => $report->plans($assets)->filter(fn (MaintenancePlan $p) => $p->status() !== 'none')
                ->map(fn (MaintenancePlan $p) => Present::plan($p))->values()->all(),
        ]]);
    }

    /**
     * Patrimonio neto (inversiones + bienes − deudas): hoy, hace un mes y hace un año, la serie
     * al cierre de cada mes (`months`, 24 por defecto, hasta 120) y lo que lo forma.
     */
    public function netWorth(Request $request, FinanceReport $finance, AssetReport $assets)
    {
        $months = min(120, max(1, $request->integer('months') ?: 24));
        $series = $finance->netWorthSeries($months)->map(fn (array $p) => ['date' => $p['date']->toDateString()] + collect($p)->except('date')->all())->values();

        return response()->json(['data' => [
            'now' => $series->last(),
            'month_ago' => $series->slice(-2, 1)->first(),
            'year_ago' => $months >= 12 ? $series->slice(-13, 1)->first() : null,
            'series' => $series,
            'accounts' => $finance->accounts()->reject->isClosed()->map(fn (InvestmentAccount $a) => ['id' => $a->id, 'name' => $a->name, 'value' => $a->currentValue()])->values(),
            'assets' => $assets->assets()->filter->isActive()->map(fn (Asset $a) => ['id' => $a->id, 'name' => $a->name, 'value' => $a->currentValue()])->values(),
            'debts' => $finance->debts()->reject->isPaidOff()->map(fn (Debt $d) => ['id' => $d->id, 'name' => $d->name, 'balance' => $d->currentBalance()])->values(),
        ]]);
    }

    /**
     * Gasto del súper (`from`, `to`: últimos 12 meses por defecto; `stores` = super | other | all).
     * Con pocos días de compras el mensual y el anual son proyección (`short_monthly`, `short_annual`).
     */
    public function spending(Request $request, ReportService $reports)
    {
        [$from, $to] = ReportService::range($request);
        $stores = array_key_exists((string) $request->query('stores'), ReportService::STORES) ? $request->query('stores') : 'super';
        $s = $reports->spending($from, $to, $stores);
        $money = fn ($v) => round((float) $v, 2);
        $share = fn ($row) => ['name' => $row->name, 'amount' => $money($row->amount), 'share' => round($row->share, 4)];

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'stores' => $stores,
            'store_options' => ReportService::STORES,
            'excluded' => $s['excluded'],
            'total' => $money($s['total']),
            'start' => $s['start']?->toDateString(),
            'end' => $s['end']?->toDateString(),
            'span' => $s['span'],
            'days' => $s['days'],
            'short_monthly' => $s['shortMonthly'],
            'short_annual' => $s['shortAnnual'],
            'daily' => $money($s['daily']),
            'weekly' => $money($s['weekly']),
            'monthly' => $money($s['monthly']),
            'annual' => $money($s['annual']),
            'lists' => $s['lists'],
            'per_list' => $money($s['perList']),
            'by_week' => $s['byWeek']->map(fn ($w) => ['week' => $w->key, 'label' => $w->label, 'amount' => $money($w->amount)])->values(),
            'by_month' => $s['byMonth']->map(fn ($m) => ['month' => $m->key, 'label' => $m->label, 'amount' => $money($m->amount)])->values(),
            'by_year' => $s['byYear']->map(fn ($y) => ['year' => (int) $y->year, 'amount' => $money($y->amount), 'lists' => $y->lists])->values(),
            'by_provider' => $s['byProvider']->map($share)->values(),
            'by_category' => $s['byCategory']->map($share)->values(),
            // Lo que no entra y suele confundir: artículos sin marcar y compras en listas futuras
            'gaps' => $reports->spendingGaps($from, $to),
        ]]);
    }

    /**
     * Inflación de la canasta propia (`from`, `to`): total y anualizada, índice mensual (base
     * 100) y la variación de cada artículo (por tienda). Los cambios van como proporción (0.05 = +5 %).
     */
    public function inflation(Request $request, ReportService $reports)
    {
        [$from, $to] = ReportService::range($request);
        $i = $reports->inflation($from, $to);
        $ratio = fn (?float $v) => $v === null ? null : round($v, 4);

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'overall' => $ratio($i['overall']),
            'annualized' => $ratio($i['annualized']),
            'up' => $i['up'],
            'down' => $i['down'],
            'flat' => $i['flat'],
            'observations' => $i['observations'],
            'monthly' => $i['monthly']->map(fn ($m) => ['month' => $m->month, 'label' => $m->label, 'index' => $m->index === null ? null : round($m->index, 2), 'change' => $ratio($m->change)])->values(),
            'products' => $i['products']->map(fn ($p) => [
                'product_id' => $p->product->id,
                'product' => $p->product->fullName(),
                'change' => $ratio($p->change),
                'first_unit_price' => round($p->first_price, 2),
                'last_unit_price' => round($p->last_price, 2),
                'per' => $p->compare_label,
                'from' => $p->from,
                'to' => $p->to,
                'providers' => $p->providers->map(fn ($r) => [
                    'provider_id' => $r->provider->id,
                    'provider' => $r->provider->name,
                    'change' => $ratio($r->change),
                    'first_unit_price' => round($r->first->comparePrice(), 2),
                    'last_unit_price' => round($r->last->comparePrice(), 2),
                ])->values(),
            ])->values(),
        ]]);
    }

    /**
     * Cambios de precio (`from`, `to`: últimos 3 meses por defecto), paginados del más nuevo
     * al más viejo: cada registro contra el anterior del mismo artículo en la misma tienda.
     */
    public function changes(Request $request, ReportService $reports)
    {
        [$from, $to] = ReportService::range($request, '-3 months');
        $changes = $reports->priceChanges($from, $to);

        return $this->page(Paginate::collection($changes, $request, $this->perPage($request)), fn ($c) => [
            'product_id' => $c->product->id,
            'product' => $c->product->fullName(),
            'provider_id' => $c->provider->id,
            'provider' => $c->provider->name,
            'date' => $c->current->observed_on->toDateString(),
            'previous_date' => $c->previous->observed_on->toDateString(),
            'days' => $c->days,
            'from_price' => $c->previous->price,
            'to_price' => $c->current->price,
            'from_unit_price' => round($c->previous->comparePrice(), 2),
            'to_unit_price' => round($c->current->comparePrice(), 2),
            'per' => $c->current->unit->compareLabel(),
            'change' => round($c->change, 4),
        ], [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'up' => $changes->where('change', '>', 0)->count(),
            'down' => $changes->where('change', '<', 0)->count(),
            'avg_change' => $changes->isEmpty() ? null : round($changes->avg('change'), 4),
        ]);
    }
}
