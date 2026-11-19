<?php

namespace App\Http\Controllers\Api;

use App\Api\Present;
use App\Models\MaintenancePlan;
use App\Services\AssetReport;

/** Reportes para la app móvil (los mismos servicios que las pantallas de reportes). */
class ReportController extends Controller
{
    /** Mantenimiento por bien y por año, costo de tener cada bien, planes próximos y garantías por vencer. */
    public function assets(AssetReport $report)
    {
        $assets = $report->assets();
        $table = $report->maintenanceByYear($assets);

        return response()->json(['data' => [
            'years' => $table['years'],
            'rows' => $table['rows']->map(fn (array $row) => [
                'asset_id' => $row['asset']->id,
                'name' => $row['asset']->name,
                'status' => $row['asset']->status,
                'by_year' => $row['byYear'],
                'total' => $row['total'],
                'value' => $row['value'],
                'gain' => $row['gain'],
                'ownership' => $row['ownership'],
            ])->values()->all(),
            'totals' => $table['totals'],
            'summary' => AssetController::summary($report->summary($assets)),
            'plans' => $report->plans($assets)->filter(fn (MaintenancePlan $p) => $p->status() !== 'none')
                ->map(fn (MaintenancePlan $p) => Present::plan($p))->values()->all(),
        ]]);
    }
}
