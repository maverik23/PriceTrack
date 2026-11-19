<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Task;
use App\Services\AssetReport;
use App\Support\AssetData;
use App\Support\AttachmentForm;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    public function index(Request $request, AssetReport $report)
    {
        $status = $request->query('status', 'active');
        $assets = Asset::with(['valuations', 'maintenanceLogs', 'meterReadings', 'maintenancePlans.logs', 'maintenancePlans.asset', 'attachments'])
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
        AttachmentForm::validate($request);
        $asset = Asset::create($this->validated($request));
        AttachmentForm::apply($asset, $request);

        return redirect()->route('assets.show', $asset)->with('success', 'Bien guardado.');
    }

    public function show(Asset $asset)
    {
        $asset->load(['valuations', 'meterReadings', 'maintenanceLogs.plan', 'maintenancePlans.logs', 'maintenancePlans.asset', 'attachments']);

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
        AttachmentForm::validate($request);
        $asset->update($this->validated($request));
        $asset->syncMaintenanceTasks();
        AttachmentForm::apply($asset, $request);

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
        return AssetData::validate($request->all());
    }
}
