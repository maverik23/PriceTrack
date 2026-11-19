<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetValuation;
use App\Models\MaintenanceLog;
use App\Models\MeterReading;
use Illuminate\Http\Request;

/** Capturas de un bien: valores, lecturas del medidor y servicios hechos. */
class AssetRecordController extends Controller
{
    public function storeValuation(Request $request, Asset $asset)
    {
        $data = $request->validate([
            'valued_on' => ['required', 'date', 'before_or_equal:today'],
            'value' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['valued_on' => 'fecha', 'value' => 'valor']);
        // Una captura por día: corrige la de ese día si ya había
        $existing = $asset->valuations()->whereDate('valued_on', $data['valued_on'])->first();
        $existing ? $existing->update($data) : $asset->valuations()->create($data);

        return $this->back($asset, 'Valor registrado.');
    }

    public function destroyValuation(Asset $asset, AssetValuation $valuation)
    {
        $valuation->delete();

        return $this->back($asset, 'Valor eliminado.');
    }

    public function storeReading(Request $request, Asset $asset)
    {
        $data = $request->validate([
            'read_on' => ['required', 'date', 'before_or_equal:today'],
            'reading' => ['required', 'numeric', 'min:0', 'max:99999999999'],
        ], [], ['read_on' => 'fecha', 'reading' => 'lectura']);
        $asset->meterReadings()->create($data);
        $asset->syncMaintenanceTasks();

        return $this->back($asset, 'Lectura registrada.');
    }

    public function destroyReading(Asset $asset, MeterReading $meterReading)
    {
        $meterReading->delete();
        $asset->syncMaintenanceTasks();

        return $this->back($asset, 'Lectura eliminada.');
    }

    public function storeLog(Request $request, Asset $asset)
    {
        $data = $request->validate([
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

        $asset->maintenanceLogs()->create($data);

        return $this->back($asset, 'Servicio registrado.');
    }

    public function destroyLog(Asset $asset, MaintenanceLog $maintenanceLog)
    {
        $maintenanceLog->delete();

        return $this->back($asset, 'Servicio eliminado.');
    }

    private function back(Asset $asset, string $message)
    {
        return redirect()->route('assets.show', $asset)->with('success', $message);
    }
}
