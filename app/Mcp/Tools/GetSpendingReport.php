<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Gasto del súper en un periodo (por defecto los últimos 12 meses): total, promedios semanal, mensual y anual, y desglose por mes, tienda y categoría. Solo cuenta los artículos marcados como comprados, en la fecha de su lista; `not_counted` dice cuántos quedaron fuera. `stores`: super (por defecto: sin las tiendas que no son súper, como Home Depot; `other_stores` dice cuánto quedó fuera), other o all. Los promedios salen de los días con compras: con menos de 28 días el mensual es null (y con menos de 90 el anual) y lo que daría al ritmo de esos días viene en `projection` con un aviso en `warning`; no lo presentes como promedio.')]
#[IsReadOnly]
class GetSpendingReport extends Tool
{
    public function handle(Request $request, ReportService $reports): Response
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'stores' => ['nullable', Rule::in(array_keys(ReportService::STORES))],
        ], [], ['from' => 'desde', 'to' => 'hasta', 'stores' => 'tiendas']);

        $to = $this->date($data['to'] ?? null, CarbonImmutable::today())->endOfDay();
        $from = $this->date($data['from'] ?? null, $to->subMonths(12)->addDay()->startOfDay());
        $stores = $data['stores'] ?? 'super';
        $spending = $reports->spending($from, $to, $stores);
        $gaps = $reports->spendingGaps($from, $to);
        $share = fn ($row) => ['name' => $row->name, 'amount' => round($row->amount, 2), 'share' => round($row->share, 3)];

        $short = $spending['total'] > 0 && $spending['shortAnnual'];

        return $this->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'stores' => $stores,
            'total' => round($spending['total'], 2),
            'shopping_trips' => $spending['lists'],
            'days_with_data' => $spending['span'],
            'first_purchase' => $spending['start']?->toDateString(),
            'average' => [
                'per_trip' => round($spending['perList'], 2),
                'weekly' => round($spending['weekly'], 2),
                'monthly' => $spending['shortMonthly'] ? null : round($spending['monthly'], 2),
                'annual' => $spending['shortAnnual'] ? null : round($spending['annual'], 2),
            ],
            ...($short ? [
                'projection' => ['monthly' => round($spending['monthly'], 2), 'annual' => round($spending['annual'], 2)],
                'warning' => "Solo hay {$spending['span']} días con compras desde el {$spending['start']->toDateString()}: "
                    .($spending['shortMonthly'] ? 'el mensual y el anual son' : 'el anual es')
                    .' lo que daría si se gastara igual todo el tiempo, no un promedio. Con al menos '
                    .($spending['shortMonthly'] ? ReportService::MIN_DAYS_MONTHLY.' días hay promedio mensual' : ReportService::MIN_DAYS_ANNUAL.' días hay promedio anual').'.',
            ] : []),
            ...($spending['excluded']['amount'] > 0 ? [
                $stores === 'super' ? 'other_stores' : 'grocery_stores' => $spending['excluded'],
            ] : []),
            'by_month' => $spending['byMonth']->map(fn ($m) => ['month' => $m->key, 'amount' => round($m->amount, 2)])->all(),
            'by_store' => $spending['byProvider']->map($share)->all(),
            'by_category' => $spending['byCategory']->map($share)->all(),
            'not_counted' => ['items_not_marked_as_purchased' => $gaps['unmarked'], 'purchases_in_future_lists' => $gaps['future']],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Desde, AAAA-MM-DD.'),
            'to' => $schema->string()->description('Hasta, AAAA-MM-DD (hoy por defecto).'),
            'stores' => $schema->string()->enum(array_keys(ReportService::STORES))->description('super (por defecto), other (las que no son súper: Home Depot…) o all.'),
        ];
    }
}
