<?php

namespace App\Http\Controllers;

use App\Services\AssetReport;
use App\Services\FinanceReport;

class NetWorthController extends Controller
{
    /** Patrimonio neto: inversiones + valor de los bienes, con su evolución. */
    public function __invoke(FinanceReport $finance, AssetReport $assets)
    {
        $series = $finance->netWorthSeries(24);
        $now = $series->last();
        $monthAgo = $series->slice(-2, 1)->first();
        $yearAgo = $series->slice(-13, 1)->first();

        return view('finance.net-worth', [
            'series' => $series,
            'now' => $now,
            'monthAgo' => $monthAgo,
            'yearAgo' => $yearAgo,
            'accounts' => $finance->accounts()->reject->isClosed(),
            'assets' => $assets->assets()->filter->isActive(),
            'debts' => $finance->debts()->reject->isPaidOff(),
        ]);
    }
}
