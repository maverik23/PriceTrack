<?php

namespace App\Http\Controllers;

use App\Models\MealPlanEntry;
use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ShoppingList;
use App\Services\ReportService;
use Carbon\CarbonImmutable;

class DashboardController extends Controller
{
    public function __invoke(ReportService $reports)
    {
        $today = CarbonImmutable::today();

        $thisMonth = $reports->spending($today->startOfMonth(), $today->endOfDay());
        $lastMonth = $reports->spending($today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth());
        $year = $reports->spending($today->subYear()->addDay()->startOfDay(), $today->endOfDay());
        $inflation = $reports->inflation($today->subYear()->addDay()->startOfDay(), $today->endOfDay());
        $changes = $reports->priceChanges($today->subDays(60)->startOfDay(), $today->endOfDay())->take(8);

        $lists = ShoppingList::with(['items.price', 'items.product.prices'])
            ->orderByDesc('shopped_on')->orderByDesc('id')
            ->take(5)->get();

        return view('dashboard', [
            'counts' => [
                'products' => Product::count(),
                'providers' => Provider::count(),
                'prices' => Price::count(),
                'lists' => ShoppingList::count(),
            ],
            'thisMonth' => $thisMonth,
            'lastMonth' => $lastMonth,
            'monthlyAvg' => $year['monthly'],
            'inflation' => $inflation,
            'changes' => $changes,
            'lists' => $lists,
            'todayMeals' => MealPlanEntry::with('recipe')
                ->whereDate('planned_on', $today)->get()
                ->sortBy(fn (MealPlanEntry $e) => array_search($e->meal, array_keys(MealPlanEntry::MEALS)))
                ->values(),
        ]);
    }
}
