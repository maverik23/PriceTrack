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

#[Description('Cómo se han movido los precios en un periodo (por defecto los últimos 12 meses): inflación de la canasta propia (total y anualizada, índice mensual base 100), los artículos que más subieron y bajaron, y los cambios de precio más recientes (cada registro contra el anterior del mismo artículo en la misma tienda, por precio unitario). Los cambios van como proporción: 0.05 = +5 %.')]
#[IsReadOnly]
class GetPriceReport extends Tool
{
    public function handle(Request $request, ReportService $reports): Response
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [], ['from' => 'desde', 'to' => 'hasta']);

        $to = $this->date($data['to'] ?? null, CarbonImmutable::today())->endOfDay();
        $from = $this->date($data['from'] ?? null, $to->subMonths(12)->addDay()->startOfDay());
        $limit = $data['limit'] ?? 15;
        $inflation = $reports->inflation($from, $to);
        $pct = fn (?float $ratio) => $ratio === null ? null : round($ratio, 4);
        $product = fn ($row) => [
            'product_id' => $row->product->id,
            'product' => $row->product->fullName(),
            'change' => $pct($row->change),
            'first_unit_price' => round($row->first_price, 2),
            'last_unit_price' => round($row->last_price, 2),
            'per' => ltrim($row->compare_label, '/'),
        ];

        return $this->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'inflation' => [
                'overall' => $pct($inflation['overall']),
                'annualized' => $pct($inflation['annualized']),
                'products_up' => $inflation['up'],
                'products_down' => $inflation['down'],
                'monthly_index' => $inflation['monthly']->filter(fn ($m) => $m->index !== null)
                    ->map(fn ($m) => ['month' => $m->month, 'index' => round($m->index, 2)])->values()->all(),
            ],
            'biggest_increases' => $inflation['products']->where('change', '>', 0)->take($limit)->map($product)->values()->all(),
            'biggest_decreases' => $inflation['products']->where('change', '<', 0)->sortBy('change')->take($limit)->map($product)->values()->all(),
            'recent_changes' => $reports->priceChanges($from, $to)->take($limit)->map(fn ($c) => [
                'product_id' => $c->product->id,
                'product' => $c->product->fullName(),
                'provider' => $c->provider->name,
                'date' => $c->current->observed_on->toDateString(),
                'from_unit_price' => round($c->previous->comparePrice(), 2),
                'to_unit_price' => round($c->current->comparePrice(), 2),
                'per' => $c->current->unit->compareLabel(),
                'change' => $pct($c->change),
            ])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Desde, AAAA-MM-DD.'),
            'to' => $schema->string()->description('Hasta, AAAA-MM-DD (hoy por defecto).'),
            'limit' => $schema->integer()->description('Cuántos renglones por sección (15 por defecto).'),
        ];
    }
}
