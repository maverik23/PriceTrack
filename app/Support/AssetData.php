<?php

namespace App\Support;

use App\Models\Asset;
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
}
