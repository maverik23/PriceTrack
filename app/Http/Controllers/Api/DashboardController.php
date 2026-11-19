<?php

namespace App\Http\Controllers\Api;

use App\Api\Present;
use App\Http\Controllers\DashboardController as WebDashboard;
use App\Mcp\Present as Base;
use App\Mcp\Tools\GetMenu;
use App\Models\Asset;
use App\Models\MaintenancePlan;
use App\Models\MealPlanEntry;
use App\Models\RecurringPayment;
use App\Models\ShoppingList;
use App\Models\Task;
use App\Services\AssetReport;
use App\Services\FinanceReport;
use App\Services\ReportService;

/** El inicio para la app móvil: lo mismo que resume la pantalla de inicio de la web. */
class DashboardController extends Controller
{
    public function __invoke(ReportService $reports, AssetReport $assetReport, FinanceReport $finance)
    {
        $d = WebDashboard::data($reports, $assetReport, $finance);
        $price = fn ($p) => ['price' => $p->price, 'presentation' => $p->presentationLabel(), 'observed_on' => $p->observed_on->toDateString(), 'compare_price' => round($p->comparePrice(), 2)];
        $plan = fn (MaintenancePlan $p) => ['asset_id' => $p->asset->id, 'asset' => $p->asset->name, 'plan' => $p->name, 'due' => $p->dueLabel(), 'status' => $p->status(), 'task_id' => $p->task_id];

        return response()->json(['data' => [
            'today' => today()->toDateString(),
            'counts' => $d['counts'],
            'groceries' => [
                'spent_this_month' => round($d['thisMonth']['total'], 2),
                'spent_last_month' => round($d['lastMonth']['total'], 2),
                'monthly_average' => round($d['monthlyAvg'], 2),
                'inflation' => $d['inflation']['overall'],
                'inflation_annualized' => $d['inflation']['annualized'],
                'recent_lists' => $d['lists']->map(fn (ShoppingList $l) => Present::shoppingList($l->loadMissing('items.price.provider')))->all(),
                'price_changes' => $d['changes']->map(fn ($c) => [
                    'product_id' => $c->product->id,
                    'product' => $c->product->fullName(),
                    'provider' => $c->provider->name,
                    'compare_label' => $c->current->unit->compareLabel(),
                    'previous' => $price($c->previous),
                    'current' => $price($c->current),
                    'change' => round($c->change, 4),
                ])->values()->all(),
            ],
            'kitchen' => [
                'today' => $d['todayMeals']->map(fn (MealPlanEntry $e) => GetMenu::entry($e))->values()->all(),
                'week_meals' => $d['weekMeals'],
                'week_cost' => $d['weekCost'],
            ],
            'home' => [
                'due_tasks' => $d['dueTasks']->map(fn (Task $t) => Base::task($t))->values()->all(),
                'overdue' => $d['overdueCount'],
                'upcoming' => $d['upcomingTasks'],
                'has_assets' => $d['hasAssets'],
                'assets_value' => $d['assetSummary']['value'],
                'maintenance_overdue' => $d['assetSummary']['overdue']->map($plan)->values()->all(),
                'maintenance_soon' => $d['assetSummary']['soon']->map($plan)->values()->all(),
                'warranties' => $d['assetSummary']['warranties']->map(fn (Asset $a) => [
                    'asset_id' => $a->id, 'asset' => $a->name, 'warranty_until' => $a->warranty_until?->toDateString(),
                ])->values()->all(),
            ],
            'finance' => [
                'has_data' => $d['hasFinance'],
                'net_worth' => $d['netWorth'],
                'net_worth_change' => $d['netWorthChange'] === null ? null : round($d['netWorthChange'], 4),
                'net_worth_delta' => $d['netWorthDelta'],
                'has_recurring' => $d['hasRecurring'],
                'due_payments' => $d['duePayments']->map(fn (RecurringPayment $p) => [
                    'id' => $p->id, 'name' => $p->name, 'amount' => $p->amount,
                    'next_due_on' => $p->next_due_on->toDateString(), 'status' => $p->status(),
                ])->values()->all(),
            ],
        ]]);
    }
}
