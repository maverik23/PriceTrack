<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\AssetValuation;
use App\Models\MaintenanceLog;
use App\Models\MeterReading;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Campos de un bien: reglas, normalización y el bien como entrada. Lo usan el formulario
 * (AssetController) y el MCP (save-asset), que para cambiar solo algunos campos parte de toInput().
 */
class AssetData
{
    /** @return array<string, mixed> los campos validados y normalizados */
    public static function validate(array $input): array
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:60'],
            'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:80'],
            'purchased_on' => ['nullable', 'date', 'before_or_equal:today'],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'warranty_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(array_keys(Asset::STATUSES))],
            'disposed_on' => ['nullable', 'required_unless:status,active', 'date', 'before_or_equal:today'],
            'disposal_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'valuation_method' => ['required', Rule::in(array_keys(Asset::METHODS))],
            'useful_life_years' => ['nullable', 'required_if:valuation_method,straight_line', 'numeric', 'gt:0', 'max:200'],
            'salvage_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'annual_rate' => ['nullable', 'required_if:valuation_method,annual_rate', 'numeric', 'between:-100,1000'],
            'meter_unit' => ['nullable', 'string', 'max:10'],
        ], [
            'disposed_on.required_unless' => 'Indica la fecha en que se vendió o se dio de baja.',
            'useful_life_years.required_if' => 'La línea recta necesita la vida útil en años.',
            'annual_rate.required_if' => 'Indica el porcentaje anual (negativo si se deprecia).',
        ], [
            'model' => 'modelo',
            'serial_number' => 'número de serie',
            'location' => 'ubicación',
            'purchased_on' => 'fecha de compra',
            'purchase_price' => 'precio de compra',
            'warranty_until' => 'garantía',
            'status' => 'estado',
            'disposed_on' => 'fecha de baja',
            'disposal_amount' => 'monto recibido',
            'valuation_method' => 'método de valor',
            'useful_life_years' => 'vida útil',
            'salvage_value' => 'valor de rescate',
            'annual_rate' => 'porcentaje anual',
            'meter_unit' => 'medidor',
        ])->validate();

        // Lo que no aplica con el estado o el método elegido se vacía
        if ($data['status'] === 'active') {
            $data['disposed_on'] = null;
            $data['disposal_amount'] = null;
        }
        if ($data['valuation_method'] !== 'straight_line') {
            $data['useful_life_years'] = null;
            $data['salvage_value'] = null;
        }
        if ($data['valuation_method'] !== 'annual_rate') {
            $data['annual_rate'] = null;
        }
        foreach (['category', 'location'] as $column) {
            $data[$column] = Asset::canonicalValue($column, $data[$column] ?? null);
        }
        $data['meter_unit'] = trim((string) ($data['meter_unit'] ?? '')) ?: null;

        return $data;
    }

    /** El bien como entrada (para cambiar solo algunos campos). */
    public static function toInput(Asset $asset): array
    {
        $input = $asset->only((new Asset)->getFillable());
        foreach (['purchased_on', 'warranty_until', 'disposed_on'] as $date) {
            $input[$date] = $asset->{$date}?->toDateString();
        }

        return $input;
    }

    /** Un valor capturado (uno por día: corrige el de ese día si ya había). */
    public static function saveValuation(Asset $asset, array $input): AssetValuation
    {
        $data = Validator::validate($input, [
            'valued_on' => ['required', 'date', 'before_or_equal:today'],
            'value' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['valued_on' => 'fecha', 'value' => 'valor']);

        $existing = $asset->valuations()->whereDate('valued_on', $data['valued_on'])->first();

        return $existing ? tap($existing)->update($data) : $asset->valuations()->create($data);
    }

    /** Una lectura del medidor (mueve las tareas de los planes por uso). */
    public static function saveReading(Asset $asset, array $input): MeterReading
    {
        $data = Validator::validate($input, [
            'read_on' => ['required', 'date', 'before_or_equal:today'],
            'reading' => ['required', 'numeric', 'min:0', 'max:99999999999'],
        ], [], ['read_on' => 'fecha', 'reading' => 'lectura']);

        $reading = $asset->meterReadings()->create($data);
        $asset->syncMaintenanceTasks();

        return $reading;
    }

    /** Un servicio hecho, opcionalmente de uno de sus planes (mueve la tarea del plan). */
    public static function saveLog(Asset $asset, array $input): MaintenanceLog
    {
        $data = Validator::validate($input, [
            'maintenance_plan_id' => ['nullable', 'exists:maintenance_plans,id'],
            'done_on' => ['required', 'date', 'before_or_equal:today'],
            'meter_reading' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'provider' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], [
            'maintenance_plan_id' => 'plan', 'done_on' => 'fecha', 'meter_reading' => 'lectura',
            'cost' => 'costo', 'provider' => 'quién lo hizo',
        ]);
        abort_if(! empty($data['maintenance_plan_id']) && ! $asset->maintenancePlans()->whereKey($data['maintenance_plan_id'])->exists(), 404);

        return $asset->maintenanceLogs()->create($data);
    }
}
