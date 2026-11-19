<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Asset;
use App\Models\AssetValuation;
use App\Models\MaintenanceLog;
use App\Models\MaintenancePlan;
use App\Models\MeterReading;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Borra algo de un bien (los `id` salen de get-asset): un valor capturado (`type: value`), una lectura (`reading`), un servicio (`service`), un plan de mantenimiento (`plan`, quita su tarea pendiente; los servicios hechos se conservan) o el bien completo (`asset`, con todo su historial y fotos). Confirma con la persona antes.')]
#[IsDestructive]
class DeleteAssetRecord extends Tool
{
    private const TYPES = [
        'value' => [AssetValuation::class, 'un valor capturado'],
        'reading' => [MeterReading::class, 'una lectura'],
        'service' => [MaintenanceLog::class, 'un servicio'],
        'plan' => [MaintenancePlan::class, 'un plan de mantenimiento'],
        'asset' => [Asset::class, 'un bien'],
    ];

    public function handle(Request $request): Response
    {
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(self::TYPES))], 'id' => ['required', 'integer']], [], ['type' => 'tipo']);
        [$class, $what] = self::TYPES[$data['type']];
        $record = $this->find($class, $data['id'], $what);
        $asset = $record instanceof Asset ? null : $record->asset;

        $record->delete();
        if ($data['type'] === 'reading') {
            $asset?->syncMaintenanceTasks();
        }

        return $this->json(['deleted' => $data['type'], 'id' => $data['id'], 'asset' => $asset?->name ?? $record->name]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(array_keys(self::TYPES))->description('value, reading, service, plan o asset.')->required(),
            'id' => $schema->integer()->description('Id de lo que se borra (de get-asset o list-assets).')->required(),
        ];
    }
}
