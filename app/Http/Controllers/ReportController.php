<?php

namespace App\Http\Controllers;

use App\Services\AssetReport;
use App\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function inflation(Request $request)
    {
        [$from, $to] = ReportService::range($request);

        return view('reports.inflation', ['from' => $from, 'to' => $to] + $this->reports->inflation($from, $to));
    }

    public function changes(Request $request)
    {
        [$from, $to] = ReportService::range($request, '-3 months');
        $changes = $this->reports->priceChanges($from, $to);

        return view('reports.changes', [
            'from' => $from,
            'to' => $to,
            'changes' => $changes,
            'up' => $changes->where('change', '>', 0)->count(),
            'down' => $changes->where('change', '<', 0)->count(),
            'avgChange' => $changes->avg('change'),
        ]);
    }

    public function spending(Request $request)
    {
        [$from, $to] = ReportService::range($request);

        return view('reports.spending', ['from' => $from, 'to' => $to, 'gaps' => $this->reports->spendingGaps($from, $to)]
            + $this->reports->spending($from, $to));
    }

    /** Mantenimiento por año, costo de tener cada bien, servicios próximos y garantías por vencer. */
    public function assets(AssetReport $report)
    {
        $assets = $report->assets();

        return view('reports.assets', [
            'table' => $report->maintenanceByYear($assets),
            'summary' => $report->summary($assets),
            'plans' => $report->plans($assets)->filter(fn ($p) => $p->status() !== 'none'),
        ]);
    }
}
