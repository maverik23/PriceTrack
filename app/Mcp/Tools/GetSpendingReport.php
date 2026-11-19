<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Gasto del súper en un periodo (por defecto los últimos 12 meses): total, promedios semanal, mensual y anual, y desglose por mes, tienda y categoría. Solo cuenta los artículos marcados como comprados, en la fecha de su lista; `not_counted` dice cuántos quedaron fuera.')]
#[IsReadOnly]
class GetSpendingReport extends Tool
{
    public function handle(Request $request, ReportService $reports): Response
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ], [], ['from' => 'desde', 'to' => 'hasta']);

        $to = $this->date($data['to'] ?? null, CarbonImmutable::today())->endOfDay();
        $from = $this->date($data['from'] ?? null, $to->subMonths(12)->addDay()->startOfDay());
        $spending = $reports->spending($from, $to);
        $gaps = $reports->spendingGaps($from, $to);
        $share = fn ($row) => ['name' => $row->name, 'amount' => round($row->amount, 2), 'share' => round($row->share, 3)];

        return $this->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total' => round($spending['total'], 2),
            'shopping_trips' => $spending['lists'],
            'average' => [
                'per_trip' => round($spending['perList'], 2),
                'weekly' => round($spending['weekly'], 2),
                'monthly' => round($spending['monthly'], 2),
                'annual' => round($spending['annual'], 2),
            ],
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
        ];
    }
}
