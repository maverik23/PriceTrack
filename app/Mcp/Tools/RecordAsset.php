<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Asset;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Registra algo en un bien (`asset_id`): su valor de hoy o de una fecha (`type: value`, `amount` = el valor; uno por día, se corrige si ya había), una lectura del medidor (`type: reading`, `amount` = la lectura en su unidad: km, horas) o un servicio hecho (`type: service`: `plan_id` del plan que cumple, `cost`, `amount` = lectura del medidor si aplica, `provider`, `notes`). Un servicio de un plan mueve su siguiente fecha y su tarea en Tareas.')]
class RecordAsset extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(['value', 'reading', 'service'])],
            'amount' => ['nullable', 'required_unless:type,service', 'numeric', 'min:0', 'max:999999999999'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'plan_id' => ['nullable', 'integer'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'provider' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['type' => 'tipo', 'amount' => 'monto', 'date' => 'fecha', 'cost' => 'costo', 'provider' => 'quién lo hizo']);

        $asset = $this->find(Asset::class, $data['asset_id'], 'un bien');
        $date = ($this->date($data['date'] ?? null) ?? today())->toDateString();

        switch ($data['type']) {
            case 'value':
                $values = ['valued_on' => $date, 'value' => $data['amount'], 'notes' => $data['notes'] ?? null];
                $existing = $asset->valuations()->whereDate('valued_on', $date)->first();
                $record = $existing ? tap($existing)->update($values) : $asset->valuations()->create($values);
                break;
            case 'reading':
                $record = $asset->meterReadings()->create(['read_on' => $date, 'reading' => $data['amount']]);
                $asset->syncMaintenanceTasks();
                break;
            default:
                if (! empty($data['plan_id']) && ! $asset->maintenancePlans()->whereKey($data['plan_id'])->exists()) {
                    throw ValidationException::withMessages(['plan_id' => 'Ese plan no es de este bien (ver get-asset).']);
                }
                $record = $asset->maintenanceLogs()->create([
                    'maintenance_plan_id' => $data['plan_id'] ?? null, 'done_on' => $date, 'meter_reading' => $data['amount'] ?? null,
                    'cost' => $data['cost'] ?? null, 'provider' => $data['provider'] ?? null, 'notes' => $data['notes'] ?? null,
                ]);
        }
        $asset->refresh();

        return $this->json([
            'recorded' => $data['type'],
            'id' => $record->id,
            'asset' => $asset->name,
            'current_value' => $asset->currentValue(),
            'current_meter' => $asset->currentMeter(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'asset_id' => $schema->integer()->description('El bien (de list-assets).')->required(),
            'type' => $schema->string()->enum(['value', 'reading', 'service'])->description('value (su valor), reading (lectura del medidor) o service (servicio hecho).')->required(),
            'amount' => $schema->number()->description('El valor (value), la lectura (reading) o, en un servicio, la lectura del medidor si aplica.'),
            'date' => $schema->string()->description('AAAA-MM-DD (hoy por defecto).'),
            'plan_id' => $schema->integer()->description('Servicio: el plan de mantenimiento que cumple (de get-asset).'),
            'cost' => $schema->number()->description('Servicio: lo que costó.'),
            'provider' => $schema->string()->description('Servicio: quién lo hizo.'),
            'notes' => $schema->string(),
        ];
    }
}
