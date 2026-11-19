<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetValuation;
use App\Models\MaintenanceLog;
use App\Models\MeterReading;
use App\Support\AssetData;
use Illuminate\Http\Request;

/** Capturas de un bien: valores, lecturas del medidor y servicios hechos. */
class AssetRecordController extends Controller
{
    public function storeValuation(Request $request, Asset $asset)
    {
        AssetData::saveValuation($asset, $request->all());

        return $this->back($asset, 'Valor registrado.');
    }

    public function destroyValuation(Asset $asset, AssetValuation $valuation)
    {
        $valuation->delete();

        return $this->back($asset, 'Valor eliminado.');
    }

    public function storeReading(Request $request, Asset $asset)
    {
        AssetData::saveReading($asset, $request->all());

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
        AssetData::saveLog($asset, $request->all());

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
