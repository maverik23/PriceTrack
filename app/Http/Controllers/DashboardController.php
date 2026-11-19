<?php

namespace App\Http\Controllers;

use App\Models\MealPlanEntry;
use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\RecurringPayment;
use App\Models\ShoppingList;
use App\Models\Task;
use App\Services\AssetReport;
use App\Services\FinanceReport;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Inicio: un resumen de cada sección (Súper, Cocina, Hogar y Finanzas). */
class DashboardController extends Controller
{
    public function __invoke(ReportService $reports, AssetReport $assetReport, FinanceReport $finance)
    {
        $today = CarbonImmutable::today();

        // Súper
        $thisMonth = $reports->spending($today->startOfMonth(), $today->endOfDay());
        $lastMonth = $reports->spending($today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth());
        $year = $reports->spending($today->subYear()->addDay()->startOfDay(), $today->endOfDay());
        $inflation = $reports->inflation($today->subYear()->addDay()->startOfDay(), $today->endOfDay());
        $changes = $reports->priceChanges($today->subDays(60)->startOfDay(), $today->endOfDay())->take(5);
        $lists = ShoppingList::with(['items.price', 'items.product.prices'])
            ->orderByDesc('shopped_on')->orderByDesc('id')
            ->take(4)->get();

        // Cocina: lo de hoy y el costo de la semana
        $week = MealPlanEntry::with('recipe.ingredients.product.prices')
            ->where('planned_on', '>=', $today->startOfWeek(CarbonInterface::MONDAY)->toDateString())
            ->where('planned_on', '<', $today->startOfWeek(CarbonInterface::MONDAY)->addWeek()->toDateString())
            ->get();
        $mealOrder = array_flip(array_keys(MealPlanEntry::MEALS));

        // Hogar
        $dueTasks = Task::with('list', 'taskable')->dueBy($today)
            ->orderBy('due_on')->orderByDesc('priority')->get();
        $assets = $assetReport->assets();
        $assetSummary = $assetReport->summary($assets);

        // Finanzas
        $accounts = $finance->accounts();
        $debts = $finance->debts();
        $netWorth = $finance->netWorthOn($today, $accounts, $assets, $debts);
        $netWorthMonthAgo = $finance->netWorthOn($today->subMonthNoOverflow(), $accounts, $assets, $debts);
        $duePayments = RecurringPayment::with('debt')->active()
            ->where('next_due_on', '<', $today->addDays(RecurringPayment::SOON_DAYS + 1)->toDateString())
            ->orderBy('next_due_on')->get();

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
            'todayMeals' => $week->filter(fn (MealPlanEntry $e) => $e->planned_on->isSameDay($today))
                ->sortBy(fn (MealPlanEntry $e) => $mealOrder[$e->meal] ?? 9)->values(),
            'weekMeals' => $week->count(),
            'weekCost' => round($week->sum(fn (MealPlanEntry $e) => $e->cost() ?? 0), 2),
            'dueTasks' => $dueTasks,
            'overdueCount' => $dueTasks->filter(fn (Task $t) => $t->status($today) === 'overdue')->count(),
            'upcomingTasks' => Task::pending()->whereNotNull('due_on')
                ->where('due_on', '>=', $today->addDay()->toDateString())
                ->where('due_on', '<', $today->addDays(8)->toDateString())->count(),
            'assetSummary' => $assetSummary,
            'hasAssets' => $assets->isNotEmpty(),
            'netWorth' => $netWorth,
            'netWorthChange' => $netWorthMonthAgo['total'] > 0 ? ($netWorth['total'] - $netWorthMonthAgo['total']) / $netWorthMonthAgo['total'] : null,
            'netWorthDelta' => round($netWorth['total'] - $netWorthMonthAgo['total'], 2),
            'hasFinance' => $accounts->isNotEmpty() || $assets->isNotEmpty() || $debts->isNotEmpty(),
            'duePayments' => $duePayments,
            'hasRecurring' => RecurringPayment::exists(),
        ]);
    }
}
