<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\MaintenancePlan;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaintenancePlanController extends Controller
{
    public function store(Request $request, Asset $asset)
    {
        $plan = $asset->maintenancePlans()->create($this->validated($request));
        $plan->syncTask();

        return redirect()->route('assets.show', $asset)->with('success', "Plan «{$plan->name}» agregado; su recordatorio está en Tareas.");
    }

    public function edit(Asset $asset, MaintenancePlan $maintenancePlan)
    {
        return view('assets.plan-edit', ['asset' => $asset, 'plan' => $maintenancePlan, 'units' => Task::REPEAT_UNITS]);
    }

    public function update(Request $request, Asset $asset, MaintenancePlan $maintenancePlan)
    {
        $maintenancePlan->update($this->validated($request));
        $maintenancePlan->syncTask();

        return redirect()->route('assets.show', $asset)->with('success', 'Plan actualizado.');
    }

    public function destroy(Asset $asset, MaintenancePlan $maintenancePlan)
    {
        $maintenancePlan->delete();

        return redirect()->route('assets.show', $asset)->with('success', 'Plan eliminado (los servicios hechos se conservan).');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'every' => ['nullable', 'integer', 'min:1', 'max:999', 'required_with:every_unit'],
            'every_unit' => ['nullable', Rule::in(array_keys(Task::REPEAT_UNITS)), 'required_with:every'],
            'every_meter' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], [
            'name' => 'qué se hace', 'every' => 'cada cuánto', 'every_unit' => 'unidad', 'every_meter' => 'cada cuánto uso',
            'estimated_cost' => 'costo estimado',
        ]);
        if (empty($data['every']) || empty($data['every_unit'])) {
            $data['every'] = null;
            $data['every_unit'] = null;
        }

        return $data;
    }
}
