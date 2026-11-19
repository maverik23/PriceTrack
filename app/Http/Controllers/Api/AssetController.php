<?php

namespace App\Http\Controllers\Api;

use App\Api\Present;
use App\Models\Asset;
use App\Models\AssetValuation;
use App\Models\MaintenanceLog;
use App\Models\MaintenancePlan;
use App\Models\MeterReading;
use App\Models\Task;
use App\Services\AssetReport;
use App\Support\AssetData;
use App\Support\AttachmentForm;
use App\Support\MaintenancePlanData;
use Illuminate\Http\Request;

/**
 * Bienes para la app móvil: el bien, sus capturas (valores, lecturas, servicios) y sus planes
 * de mantenimiento. Las reglas son las de la web (AssetData, MaintenancePlanData).
 */
class AssetController extends Controller
{
    private const LIST = ['valuations', 'meterReadings', 'maintenanceLogs', 'maintenancePlans.logs', 'attachments'];

    private const WITH = ['valuations', 'meterReadings', 'maintenanceLogs.plan', 'maintenancePlans.logs', 'attachments'];

    /**
     * `status` = active (por defecto) | sold | retired | all, `category`, `q`; con `detail=1`
     * la ficha de cada uno. Además el resumen (valor total, mantenimiento del año, planes
     * vencidos o próximos, garantías por vencer).
     */
    public function index(Request $request, AssetReport $report)
    {
        $status = $request->query('status', 'active');
        $detail = $request->boolean('detail');
        $assets = Asset::with($detail ? self::WITH : self::LIST)
            ->when(in_array($status, array_keys(Asset::STATUSES), true), fn ($q) => $q->where('status', $status))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('category', 'like', $term)->orWhere('brand', 'like', $term)->orWhere('model', 'like', $term)
                    ->orWhere('serial_number', 'like', $term)->orWhere('location', 'like', $term));
            })
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->page($assets, fn (Asset $a) => $detail ? Present::assetDetail($a) : Present::asset($a), [
            'status' => $status,
            'summary' => self::summary($report->summary()),
        ]);
    }

    /** Lo que necesitan los formularios: estados, métodos de valor, unidades de los planes, categorías y ubicaciones. */
    public function options()
    {
        return response()->json([
            'statuses' => Asset::STATUSES,
            'methods' => Asset::METHODS,
            'units' => collect(Task::REPEAT_UNITS)->map(fn (array $names) => ['one' => $names[0], 'many' => $names[1]]),
            'categories' => Asset::distinctValues('category'),
            'locations' => Asset::distinctValues('location'),
        ]);
    }

    public function show(Asset $asset)
    {
        return response()->json(['data' => $this->detail($asset)]);
    }

    public function store(Request $request)
    {
        $asset = Asset::create(AssetData::validate($request->all() + ['status' => 'active', 'valuation_method' => 'none']));

        return response()->json(['data' => $this->detail($asset), 'message' => 'Bien guardado.'], 201);
    }

    /** Solo cambia lo que se manda. Venderlo o darlo de baja quita sus recordatorios pendientes. */
    public function update(Request $request, Asset $asset)
    {
        $asset->update(AssetData::validate($request->all() + AssetData::toInput($asset)));
        $asset->syncMaintenanceTasks();

        return response()->json(['data' => $this->detail($asset), 'message' => 'Bien actualizado.']);
    }

    public function destroy(Asset $asset)
    {
        $asset->delete();

        return response()->json(['message' => "«{$asset->name}» eliminado."]);
    }

    /** Fotos y documentos (multipart), como en artículos. */
    public function attachments(Request $request, Asset $asset)
    {
        AttachmentForm::validate($request);
        AttachmentForm::apply($asset, $request);

        return response()->json(['data' => $this->detail($asset)]);
    }

    // ---------- Capturas ----------

    /** `valued_on`, `value`, `notes?`: una por día (corrige la de ese día). */
    public function storeValuation(Request $request, Asset $asset)
    {
        AssetData::saveValuation($asset, $request->all());

        return $this->saved($asset, 'Valor registrado.');
    }

    public function destroyValuation(Asset $asset, AssetValuation $valuation)
    {
        $valuation->delete();

        return $this->saved($asset, 'Valor eliminado.');
    }

    /** `read_on`, `reading`. */
    public function storeReading(Request $request, Asset $asset)
    {
        AssetData::saveReading($asset, $request->all());

        return $this->saved($asset, 'Lectura registrada.');
    }

    public function destroyReading(Asset $asset, MeterReading $meterReading)
    {
        $meterReading->delete();
        $asset->syncMaintenanceTasks();

        return $this->saved($asset, 'Lectura eliminada.');
    }

    /** `done_on`, `maintenance_plan_id?`, `meter_reading?`, `cost?`, `provider?`, `notes?`. */
    public function storeLog(Request $request, Asset $asset)
    {
        AssetData::saveLog($asset, $request->all());

        return $this->saved($asset, 'Servicio registrado.');
    }

    public function destroyLog(Asset $asset, MaintenanceLog $maintenanceLog)
    {
        $maintenanceLog->delete();

        return $this->saved($asset, 'Servicio eliminado.');
    }

    // ---------- Planes de mantenimiento ----------

    public function storePlan(Request $request, Asset $asset)
    {
        $plan = $asset->maintenancePlans()->create(MaintenancePlanData::validate($request->all()));
        $plan->syncTask();

        return $this->saved($asset, "Plan «{$plan->name}» agregado; su recordatorio está en Tareas.", 201);
    }

    /** Solo cambia lo que se manda. */
    public function updatePlan(Request $request, Asset $asset, MaintenancePlan $maintenancePlan)
    {
        $maintenancePlan->update(MaintenancePlanData::validate($request->all() + MaintenancePlanData::toInput($maintenancePlan)));
        $maintenancePlan->syncTask();

        return $this->saved($asset, 'Plan actualizado.');
    }

    public function destroyPlan(Asset $asset, MaintenancePlan $maintenancePlan)
    {
        $maintenancePlan->delete();

        return $this->saved($asset, 'Plan eliminado (los servicios hechos se conservan).');
    }

    /**
     * Las tarjetas del listado y del reporte.
     *
     * @return array<string, mixed>
     */
    public static function summary(array $summary): array
    {
        return [
            'value' => $summary['value'],
            'maintenance_year' => $summary['maintenanceYear'],
            'overdue' => $summary['overdue']->map(fn (MaintenancePlan $p) => Present::plan($p))->values()->all(),
            'soon' => $summary['soon']->map(fn (MaintenancePlan $p) => Present::plan($p))->values()->all(),
            'warranties' => $summary['warranties']->map(fn (Asset $a) => [
                'id' => $a->id, 'name' => $a->name, 'warranty_until' => $a->warranty_until?->toDateString(),
            ])->values()->all(),
        ];
    }

    private function saved(Asset $asset, string $message, int $status = 200)
    {
        return response()->json(['data' => $this->detail($asset), 'message' => $message], $status);
    }

    /** @return array<string, mixed> */
    private function detail(Asset $asset): array
    {
        return Present::assetDetail($asset->fresh(self::WITH));
    }
}
