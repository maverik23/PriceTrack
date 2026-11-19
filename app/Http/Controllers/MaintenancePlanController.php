<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\MaintenancePlan;
use App\Models\Task;
use App\Support\MaintenancePlanData;
use Illuminate\Http\Request;

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
        return MaintenancePlanData::validate($request->all());
    }
}
