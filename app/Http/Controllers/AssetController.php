<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Task;
use App\Services\AssetReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    public function index(Request $request, AssetReport $report)
    {
        $status = $request->query('status', 'active');
        $assets = Asset::with(['valuations', 'maintenanceLogs', 'meterReadings', 'maintenancePlans.logs', 'maintenancePlans.asset'])
            ->when(in_array($status, array_keys(Asset::STATUSES), true), fn ($q) => $q->where('status', $status))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->orderBy('name')
            ->get();

        return view('assets.index', [
            'assets' => $assets,
            'status' => $status,
            'categories' => Asset::distinctValues('category'),
            'summary' => $report->summary(),
        ]);
    }

    public function create()
    {
        return view('assets.create', $this->formData(new Asset(['status' => 'active', 'valuation_method' => 'none'])));
    }

    public function store(Request $request)
    {
        $asset = Asset::create($this->validated($request));

        return redirect()->route('assets.show', $asset)->with('success', 'Bien guardado.');
    }

    public function show(Asset $asset)
    {
        $asset->load(['valuations', 'meterReadings', 'maintenanceLogs.plan', 'maintenancePlans.logs', 'maintenancePlans.asset']);

        return view('assets.show', [
            'asset' => $asset,
            'series' => $asset->valueSeries(),
            'tasks' => $asset->tasks()->pending()->orderByRaw('due_on is null')->orderBy('due_on')->get(),
            'units' => Task::REPEAT_UNITS,
        ]);
    }

    public function edit(Asset $asset)
    {
        return view('assets.edit', $this->formData($asset));
    }

    public function update(Request $request, Asset $asset)
    {
        $asset->update($this->validated($request));
        $asset->syncMaintenanceTasks();

        return redirect()->route('assets.show', $asset)->with('success', 'Bien actualizado.');
    }

    public function destroy(Asset $asset)
    {
        $asset->delete();

        return redirect()->route('assets.index')->with('success', "«{$asset->name}» eliminado.");
    }

    private function formData(Asset $asset): array
    {
        return [
            'asset' => $asset,
            'categories' => Asset::distinctValues('category'),
            'locations' => Asset::distinctValues('location'),
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
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
        ]);

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
}
