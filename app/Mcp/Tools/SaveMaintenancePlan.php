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

#[Description('Crea un plan de mantenimiento de un bien (`asset_id`) o, con `id`, cambia uno existente (solo lo que se manda). Por tiempo (`every` + `every_unit`: cada 6 meses) y/o por uso (`every_meter`: cada 10,000 km). Cada plan mantiene su recordatorio en Tareas (lista "Mantenimiento"); al registrar el servicio con record-asset (`type: service`, `plan_id`) se mueve solo. Con `last_done_on` (y `last_meter` si va por uso) se registra la última vez que se hizo, para que el siguiente se cuente desde ahí y un plan nuevo no nazca vencido.')]
class SaveMaintenancePlan extends Tool
{
    public function handle(Request $request): Response
    {
        $last = $request->validate([
            'id' => ['nullable', 'integer'],
            'asset_id' => ['nullable', 'required_without:id', 'integer'],
            'last_done_on' => ['nullable', 'date', 'before_or_equal:today', 'required_with:last_meter'],
            'last_meter' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ], [], ['last_done_on' => 'la última vez', 'last_meter' => 'la lectura de la última vez']);
        $plan = $request->get('id')
            ? $this->find(MaintenancePlan::class, $request->get('id'), 'un plan de mantenimiento')
            : $this->find(Asset::class, $request->get('asset_id'), 'un bien')->maintenancePlans()->make();
        $given = collect($request->all())->except(['id', 'asset_id', 'last_done_on', 'last_meter'])->all();
        $data = MaintenancePlanData::validate($plan->exists ? array_merge(MaintenancePlanData::toInput($plan), $given) : $given);

        $creating = ! $plan->exists;
        $plan->fill($data)->save();
        $plan->syncTask();

        // La última vez que se hizo = un servicio de este plan en esa fecha (mueve el siguiente y su tarea)
        $lastLog = null;
        if (! empty($last['last_done_on'])) {
            $date = $this->date($last['last_done_on'])->toDateString();
            $values = ['done_on' => $date, 'meter_reading' => $last['last_meter'] ?? null];
            $lastLog = $plan->logs()->whereDate('done_on', $date)->first();
            $lastLog = $lastLog
                ? tap($lastLog)->update(array_filter($values, fn ($v) => $v !== null))
                : $plan->asset->maintenanceLogs()->create($values + ['maintenance_plan_id' => $plan->id]);
            $plan = $plan->fresh(['asset', 'logs']);
        }

        return $this->json([
            'saved' => $creating ? 'created' : 'updated',
            'plan' => ['id' => $plan->id, 'asset' => $plan->asset->name, 'name' => $plan->name, 'every' => $plan->intervalLabel(), 'next_due' => $plan->dueLabel() ?: null, 'task_id' => $plan->fresh()->task_id,
                'last_done' => $lastLog ? ['log_id' => $lastLog->id, 'date' => $lastLog->done_on->toDateString(), 'meter' => $lastLog->meter_reading] : null],
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
            'last_done_on' => $schema->string()->description('AAAA-MM-DD: la última vez que se hizo (no después de hoy). Se registra como un servicio del plan y el siguiente se cuenta desde ahí; sin ella, desde la compra del bien o desde hoy.'),
            'last_meter' => $schema->number()->description('La lectura del medidor esa última vez (km, horas), para los planes por uso. Va con last_done_on.'),
            'estimated_cost' => $schema->number(),
            'notes' => $schema->string(),
        ];
    }
}
