<?php

namespace App\Support;

use App\Models\MaintenancePlan;
use App\Models\Task;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Campos de un plan de mantenimiento (MaintenancePlanController y el MCP save-maintenance-plan). */
class MaintenancePlanData
{
    public static function validate(array $input): array
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'every' => ['nullable', 'integer', 'min:1', 'max:999', 'required_with:every_unit'],
            'every_unit' => ['nullable', Rule::in(array_keys(Task::REPEAT_UNITS)), 'required_with:every'],
            'every_meter' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], [
            'name' => 'qué se hace', 'every' => 'cada cuánto', 'every_unit' => 'unidad', 'every_meter' => 'cada cuánto uso',
            'estimated_cost' => 'costo estimado',
        ])->validate();
        if (empty($data['every']) || empty($data['every_unit'])) {
            $data['every'] = null;
            $data['every_unit'] = null;
        }

        return $data;
    }

    public static function toInput(MaintenancePlan $plan): array
    {
        return $plan->only(['name', 'every', 'every_unit', 'every_meter', 'estimated_cost', 'notes']);
    }
}
