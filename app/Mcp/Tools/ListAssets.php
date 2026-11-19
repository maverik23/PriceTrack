<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Asset;
use App\Models\MaintenancePlan;
use App\Services\AssetReport;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Bienes del hogar (auto, casa, electrodomésticos…): valor estimado de hoy, ganancia o pérdida contra la compra, garantía, medidor, costo de mantenimiento del año y sus planes de mantenimiento con el estado de cada uno (overdue = vencido, soon = pronto, ok). Cada plan tiene su recordatorio en las tareas (`task_id`).')]
#[IsReadOnly]
class ListAssets extends Tool
{
    public function handle(Request $request, AssetReport $report): Response
    {
        $assets = $report->assets();
        $summary = $report->summary($assets);

        return $this->json([
            'total_value' => $summary['value'],
            'maintenance_cost_this_year' => $summary['maintenanceYear'],
            'assets' => $assets->map(fn (Asset $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'category' => $a->category,
                'status' => $a->status,
                'location' => $a->location,
                'purchased_on' => $a->purchased_on?->toDateString(),
                'purchase_price' => $a->purchase_price,
                'current_value' => $a->isActive() ? $a->currentValue() : null,
                'gain' => $a->gain(),
                'warranty_until' => $a->warranty_until?->toDateString(),
                'meter' => $a->meter_unit ? ['reading' => $a->currentMeter(), 'unit' => $a->meter_unit] : null,
                'maintenance_cost_this_year' => $a->maintenanceCost(today()->year),
                'maintenance_plans' => $a->maintenancePlans->map(fn (MaintenancePlan $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'every' => $p->intervalLabel(),
                    'status' => $p->status(),
                    'next_due' => $p->dueLabel() ?: null,
                    'last_done_on' => $p->lastLog()?->done_on?->toDateString(),
                    'estimated_cost' => $p->estimated_cost,
                    'task_id' => $p->task_id,
                ])->all(),
                'url' => route('assets.show', $a),
            ])->all(),
        ]);
    }
}
