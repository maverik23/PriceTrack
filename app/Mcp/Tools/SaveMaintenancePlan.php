<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Asset;
use App\Models\MaintenancePlan;
use App\Models\Task;
use App\Support\MaintenancePlanData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Crea un plan de mantenimiento de un bien (`asset_id`) o, con `id`, cambia uno existente (solo lo que se manda). Por tiempo (`every` + `every_unit`: cada 6 meses) y/o por uso (`every_meter`: cada 10,000 km). Cada plan mantiene su recordatorio en Tareas (lista "Mantenimiento"); al registrar el servicio con record-asset (`type: service`, `plan_id`) se mueve solo.')]
class SaveMaintenancePlan extends Tool
{
    public function handle(Request $request): Response
    {
        $request->validate(['id' => ['nullable', 'integer'], 'asset_id' => ['nullable', 'required_without:id', 'integer']]);
        $plan = $request->get('id')
            ? $this->find(MaintenancePlan::class, $request->get('id'), 'un plan de mantenimiento')
            : $this->find(Asset::class, $request->get('asset_id'), 'un bien')->maintenancePlans()->make();
        $given = collect($request->all())->except(['id', 'asset_id'])->all();
        $data = MaintenancePlanData::validate($plan->exists ? array_merge(MaintenancePlanData::toInput($plan), $given) : $given);

        $creating = ! $plan->exists;
        $plan->fill($data)->save();
        $plan->syncTask();

        return $this->json([
            'saved' => $creating ? 'created' : 'updated',
            'plan' => ['id' => $plan->id, 'asset' => $plan->asset->name, 'name' => $plan->name, 'every' => $plan->intervalLabel(), 'next_due' => $plan->dueLabel() ?: null, 'task_id' => $plan->fresh()->task_id],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Para cambiar uno existente (de get-asset). Sin él se crea.'),
            'asset_id' => $schema->integer()->description('El bien (obligatorio al crear).'),
            'name' => $schema->string()->description('Qué se hace: "Cambio de aceite", "Limpieza de filtros".'),
            'every' => $schema->integer()->description('Cada cuánto (con every_unit).'),
            'every_unit' => $schema->string()->enum(array_keys(Task::REPEAT_UNITS))->description('day, week, month o year.'),
            'every_meter' => $schema->number()->description('Cada cuánto uso, en la unidad del medidor del bien.'),
            'estimated_cost' => $schema->number(),
            'notes' => $schema->string(),
        ];
    }
}
