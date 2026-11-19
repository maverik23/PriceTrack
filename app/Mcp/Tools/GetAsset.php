<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Asset;
use App\Models\AssetValuation;
use App\Models\MaintenanceLog;
use App\Models\MaintenancePlan;
use App\Models\MeterReading;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Ficha completa de un bien (`asset_id`, de list-assets): todos sus datos (marca, modelo, serie, ubicación, compra, garantía, método de valor, medidor), su valor de hoy, y con sus `id` los valores capturados, las lecturas del medidor, los servicios hechos, los planes de mantenimiento y sus tareas pendientes. Úsala antes de corregir o borrar algo de un bien.')]
#[IsReadOnly]
class GetAsset extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate(['asset_id' => ['required', 'integer']]);
        $asset = $this->find(Asset::class, $data['asset_id'], 'un bien', ['valuations', 'meterReadings', 'maintenanceLogs.plan', 'maintenancePlans.logs']);

        return $this->json([
            'asset' => $asset->only(['id', 'name', 'category', 'brand', 'model', 'serial_number', 'location', 'purchase_price', 'notes', 'status', 'disposal_amount', 'valuation_method', 'useful_life_years', 'salvage_value', 'annual_rate', 'meter_unit']) + [
                'purchased_on' => $asset->purchased_on?->toDateString(),
                'warranty_until' => $asset->warranty_until?->toDateString(),
                'disposed_on' => $asset->disposed_on?->toDateString(),
                'current_value' => $asset->currentValue(),
                'gain' => $asset->gain(),
                'current_meter' => $asset->currentMeter(),
                'maintenance_cost_total' => $asset->maintenanceCost(),
                'url' => route('assets.show', $asset),
            ],
            'values' => $asset->valuations->sortByDesc('valued_on')->map(fn (AssetValuation $v) => [
                'id' => $v->id, 'valued_on' => $v->valued_on->toDateString(), 'value' => $v->value, 'notes' => $v->notes,
            ])->values()->all(),
            'readings' => $asset->meterReadings->sortByDesc('read_on')->map(fn (MeterReading $r) => [
                'id' => $r->id, 'read_on' => $r->read_on->toDateString(), 'reading' => $r->reading,
            ])->values()->all(),
            'services' => $asset->maintenanceLogs->sortByDesc('done_on')->map(fn (MaintenanceLog $l) => [
                'id' => $l->id, 'done_on' => $l->done_on->toDateString(), 'plan' => $l->plan?->name, 'plan_id' => $l->maintenance_plan_id,
                'cost' => $l->cost, 'meter_reading' => $l->meter_reading, 'provider' => $l->provider, 'notes' => $l->notes,
            ])->values()->all(),
            'plans' => $asset->maintenancePlans->map(fn (MaintenancePlan $p) => [
                'id' => $p->id, 'name' => $p->name, 'every' => $p->every, 'every_unit' => $p->every_unit, 'every_meter' => $p->every_meter,
                'estimated_cost' => $p->estimated_cost, 'status' => $p->status(), 'next_due' => $p->dueLabel() ?: null, 'task_id' => $p->task_id,
            ])->all(),
            'pending_tasks' => $asset->tasks()->pending()->get()->map(fn (Task $t) => [
                'id' => $t->id, 'title' => $t->title, 'due_on' => $t->due_on?->toDateString(),
            ])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['asset_id' => $schema->integer()->description('Id del bien (de list-assets).')->required()];
    }
}
