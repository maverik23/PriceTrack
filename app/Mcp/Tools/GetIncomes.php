<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Income;
use App\Models\IncomeSource;
use App\Services\IncomeReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Ingresos de un mes (el actual por defecto): lo recibido (en dinero y en especie: vales, regalos, con su valor), por categoría, lo gastado (súper y pagos) y el balance; los ingresos recurrentes (sueldo, renta, vales) con su próxima fecha, y lo que se espera al mes.')]
#[IsReadOnly]
class GetIncomes extends Tool
{
    public function handle(Request $request, IncomeReport $report): Response
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']], [], ['month' => 'mes']);
        $month = isset($data['month']) ? CarbonImmutable::createFromFormat('!Y-m', $data['month']) : CarbonImmutable::today()->startOfMonth();
        $r = $report->month($month);

        return $this->json([
            'month' => $month->format('Y-m'),
            'total' => round($r['total'], 2),
            'money' => round($r['money'], 2),
            'in_kind' => round($r['inKind'], 2),
            'spent' => ['super' => round($r['super'], 2), 'payments' => round($r['payments'], 2), 'total' => round($r['spent'], 2)],
            'balance' => round($r['balance'], 2),
            'by_category' => $r['byCategory']->map(fn ($v) => round($v, 2))->all(),
            'incomes' => $r['incomes']->map(fn (Income $i) => [
                'id' => $i->id, 'name' => $i->name, 'received_on' => $i->received_on->toDateString(),
                'amount' => $i->amount, 'kind' => $i->kind, 'category' => $i->category,
            ])->values()->all(),
            'recurring' => IncomeSource::active()->orderBy('next_on')->get()->map(fn (IncomeSource $s) => [
                'id' => $s->id, 'name' => $s->name, 'amount' => $s->amount, 'kind' => $s->kind,
                'interval' => $s->intervalLabel(), 'next_on' => $s->next_on->toDateString(), 'status' => $s->status(),
            ])->all(),
            'expected_monthly' => $report->expectedMonthly(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['month' => $schema->string()->description('Mes AAAA-MM (el actual por defecto).')];
    }
}
