<?php

namespace App\Services;

use App\Models\Price;
use App\Models\ShoppingListItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ReportService
{
    /**
     * Rango de fechas desde el request (?from=&to=). Por defecto: últimos 12 meses.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(Request $request, string $defaultFrom = '-12 months'): array
    {
        $to = $request->date('to')?->toImmutable() ?? CarbonImmutable::today();
        $from = $request->date('from')?->toImmutable() ?? CarbonImmutable::today()->modify($defaultFrom)->addDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }

    /**
     * Cambios de precio: compara cada registro con el anterior del mismo
     * artículo + proveedor (por precio unitario, así un cambio de presentación
     * no se confunde con un cambio de precio).
     *
     * @return Collection<int, object>
     */
    public function priceChanges(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $prices = Price::with(['product', 'provider'])
            ->where('observed_on', '<=', $to->toDateString())
            ->orderBy('observed_on')
            ->orderBy('id')
            ->get();

        $changes = collect();

        foreach ($prices->groupBy(fn (Price $p) => $p->product_id.'-'.$p->provider_id) as $group) {
            $previous = null;
            foreach ($group as $price) {
                if ($previous
                    && $previous->unit_price > 0
                    && abs($price->unit_price - $previous->unit_price) > 0.0000001
                    && $price->observed_on->betweenIncluded($from, $to)) {
                    $changes->push((object) [
                        'product' => $price->product,
                        'provider' => $price->provider,
                        'previous' => $previous,
                        'current' => $price,
                        'change' => $price->unit_price / $previous->unit_price - 1,
                        'days' => (int) $previous->observed_on->diffInDays($price->observed_on),
                    ]);
                }
                $previous = $price;
            }
        }

        return $changes
            ->sortByDesc(fn ($c) => $c->current->observed_on->format('Ymd').str_pad((string) $c->current->id, 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * Inflación de la canasta personal.
     *
     * - Índice mensual encadenado (base 100): por cada par artículo+proveedor se
     *   toma el precio unitario promedio del mes (arrastrando el último conocido
     *   si no hubo registro) y se promedia geométricamente la variación mes a mes.
     * - Variación por artículo: primer vs último precio unitario del rango,
     *   promediado entre proveedores.
     */
    public function inflation(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $prices = Price::with(['product', 'provider'])
            ->whereBetween('observed_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('observed_on')
            ->orderBy('id')
            ->get();

        $pairs = $prices->groupBy(fn (Price $p) => $p->product_id.'-'.$p->provider_id);

        // ---- Índice mensual encadenado ----
        $months = collect(CarbonPeriod::create($from->startOfMonth(), '1 month', $to->startOfMonth()))
            ->map(fn ($d) => $d->format('Y-m'))
            ->values();

        $filled = [];
        foreach ($pairs as $key => $group) {
            $byMonth = $group->groupBy(fn (Price $p) => $p->observed_on->format('Y-m'))
                ->map(fn (Collection $ps) => $ps->avg('unit_price'));
            $last = null;
            foreach ($months as $m) {
                if (isset($byMonth[$m])) {
                    $last = $byMonth[$m];
                }
                if ($last !== null) {
                    $filled[$key][$m] = $last;
                }
            }
        }

        $index = [];
        $current = null;
        foreach ($months as $i => $m) {
            if ($current === null) {
                foreach ($filled as $series) {
                    if (isset($series[$m])) {
                        $current = 100.0;
                        break;
                    }
                }
                $index[$m] = $current;

                continue;
            }

            $prev = $months[$i - 1];
            $logs = [];
            foreach ($filled as $series) {
                if (isset($series[$prev], $series[$m]) && $series[$prev] > 0 && $series[$m] > 0) {
                    $logs[] = log($series[$m] / $series[$prev]);
                }
            }
            $current *= $logs ? exp(array_sum($logs) / count($logs)) : 1;
            $index[$m] = $current;
        }

        $indexed = array_filter($index, fn ($v) => $v !== null);
        $overall = $indexed ? end($indexed) / 100 - 1 : null;
        $monthsSpan = max(count($indexed) - 1, 0);
        $annualized = ($overall !== null && $monthsSpan >= 3)
            ? pow(1 + $overall, 12 / $monthsSpan) - 1
            : null;

        $monthly = collect($index)->map(function ($value, $month) use ($index) {
            $prevMonth = CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth()->subMonth()->format('Y-m');
            $prevValue = $index[$prevMonth] ?? null;

            return (object) [
                'month' => $month,
                'label' => ucfirst(CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth()->translatedFormat('M Y')),
                'index' => $value,
                'change' => ($value !== null && $prevValue) ? $value / $prevValue - 1 : null,
            ];
        })->values();

        // ---- Variación por artículo ----
        $pairChanges = $pairs
            ->filter(fn (Collection $g) => $g->count() >= 2 && $g->first()->unit_price > 0)
            ->map(fn (Collection $g) => (object) [
                'product' => $g->first()->product,
                'provider' => $g->first()->provider,
                'first' => $g->first(),
                'last' => $g->last(),
                'change' => $g->last()->unit_price / $g->first()->unit_price - 1,
            ]);

        $products = $pairChanges
            ->groupBy(fn ($row) => $row->product->id)
            ->map(fn (Collection $rows) => (object) [
                'product' => $rows->first()->product,
                'change' => $rows->avg('change'),
                'first_price' => $rows->avg(fn ($r) => $r->first->comparePrice()),
                'last_price' => $rows->avg(fn ($r) => $r->last->comparePrice()),
                'compare_label' => $rows->first()->first->compareLabel(),
                'from' => $rows->min(fn ($r) => $r->first->observed_on->format('Y-m-d')),
                'to' => $rows->max(fn ($r) => $r->last->observed_on->format('Y-m-d')),
                'providers' => $rows->sortByDesc('change')->values(),
            ])
            ->sortByDesc('change')
            ->values();

        return [
            'overall' => $overall,
            'annualized' => $annualized,
            'monthly' => $monthly,
            'products' => $products,
            'up' => $products->where('change', '>', 0)->count(),
            'down' => $products->where('change', '<', 0)->count(),
            'flat' => $products->where('change', 0)->count(),
            'observations' => $prices->count(),
        ];
    }

    /**
     * Gasto: artículos de listas con precio elegido (lo comprado).
     * Los promedios se calculan con la tasa diaria del periodo real
     * (desde la primera compra del rango hasta el fin del rango / hoy),
     * de al menos una semana: con una sola compra hoy, el promedio semanal
     * es esa compra y no 7 veces ella.
     */
    /**
     * Lo que no entra en el gasto del periodo y suele confundir: artículos de
     * listas ya pasadas sin marcar como comprados y compras en listas con fecha futura.
     *
     * @return array{unmarked: int, future: int}
     */
    public function spendingGaps(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $today = CarbonImmutable::today();

        return [
            'unmarked' => ShoppingListItem::whereNull('price_id')
                ->whereHas('shoppingList', fn ($q) => $q->whereBetween('shopped_on', [$from->toDateString(), $to->min($today)->toDateString()]))
                ->count(),
            'future' => ShoppingListItem::whereNotNull('price_id')
                ->whereHas('shoppingList', fn ($q) => $q->where('shopped_on', '>', $today->toDateString()))
                ->count(),
        ];
    }

    public function spending(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $items = ShoppingListItem::with(['price.provider', 'product', 'shoppingList'])
            ->whereNotNull('price_id')
            ->whereHas('shoppingList', fn ($q) => $q->whereBetween('shopped_on', [$from->toDateString(), $to->toDateString()]))
            ->get();

        $rows = $items->map(fn (ShoppingListItem $item) => (object) [
            'date' => $item->shoppingList->shopped_on->toImmutable(),
            'amount' => $item->subtotal() ?? 0,
            'provider' => $item->price->provider->name,
            'category' => $item->product->category ?: 'Sin categoría',
            'list_id' => $item->shopping_list_id,
        ])->sortBy(fn ($r) => $r->date->timestamp)->values();

        $total = (float) $rows->sum('amount');

        if ($rows->isEmpty()) {
            return [
                'total' => 0.0, 'days' => 0, 'span' => 0, 'start' => null, 'end' => null,
                'daily' => 0.0, 'weekly' => 0.0, 'monthly' => 0.0, 'annual' => 0.0,
                'lists' => 0, 'perList' => 0.0,
                'byWeek' => collect(), 'byMonth' => collect(), 'byYear' => collect(),
                'byProvider' => collect(), 'byCategory' => collect(),
            ];
        }

        $start = $rows->first()->date->max($from)->startOfDay();
        $end = $to->min(CarbonImmutable::today())->max($rows->last()->date)->startOfDay();
        $span = (int) $start->diffInDays($end) + 1;
        $days = max(7, $span);
        $daily = $total / $days;
        $lists = $rows->pluck('list_id')->unique()->count();

        // Semanas (lunes) y meses con relleno en ceros para las gráficas
        $weeks = collect(CarbonPeriod::create($start->startOfWeek(), '1 week', $end->startOfWeek()))
            ->mapWithKeys(fn ($d) => [$d->format('Y-m-d') => 0.0]);
        foreach ($rows as $r) {
            $key = $r->date->startOfWeek()->format('Y-m-d');
            $weeks[$key] = ($weeks[$key] ?? 0) + $r->amount;
        }

        $monthsMap = collect(CarbonPeriod::create($start->startOfMonth(), '1 month', $end->startOfMonth()))
            ->mapWithKeys(fn ($d) => [$d->format('Y-m') => 0.0]);
        foreach ($rows as $r) {
            $key = $r->date->format('Y-m');
            $monthsMap[$key] = ($monthsMap[$key] ?? 0) + $r->amount;
        }

        $byWeek = $weeks->map(fn ($amount, $week) => (object) [
            'key' => $week,
            'label' => CarbonImmutable::parse($week)->translatedFormat('d M y'),
            'amount' => $amount,
        ])->values();

        $byMonth = $monthsMap->map(fn ($amount, $month) => (object) [
            'key' => $month,
            'label' => ucfirst(CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth()->translatedFormat('M Y')),
            'amount' => $amount,
        ])->values();

        $byYear = $rows->groupBy(fn ($r) => $r->date->format('Y'))
            ->map(fn (Collection $rs, $year) => (object) [
                'year' => $year,
                'amount' => (float) $rs->sum('amount'),
                'lists' => $rs->pluck('list_id')->unique()->count(),
            ])->values();

        $share = fn (Collection $grouped) => $grouped
            ->map(fn (Collection $rs, $name) => (object) [
                'name' => $name,
                'amount' => (float) $rs->sum('amount'),
                'share' => $total > 0 ? $rs->sum('amount') / $total : 0,
            ])
            ->sortByDesc('amount')
            ->values();

        return [
            'total' => $total,
            'days' => $days,
            'span' => $span,
            'start' => $start,
            'end' => $end,
            'daily' => $daily,
            'weekly' => $daily * 7,
            'monthly' => $daily * 365.25 / 12,
            'annual' => $daily * 365.25,
            'lists' => $lists,
            'perList' => $lists ? $total / $lists : 0.0,
            'byWeek' => $byWeek,
            'byMonth' => $byMonth,
            'byYear' => $byYear,
            'byProvider' => $share($rows->groupBy('provider')),
            'byCategory' => $share($rows->groupBy('category')),
        ];
    }
}
