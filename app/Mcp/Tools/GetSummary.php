<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\MaintenancePlan;
use App\Models\MealPlanEntry;
use App\Models\RecurringPayment;
use App\Models\ShoppingList;
use App\Models\Task;
use App\Services\AssetReport;
use App\Services\FinanceReport;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Resumen del hogar para hoy, como la pantalla de inicio: gasto del súper del mes, listas recientes, qué se come hoy, tareas vencidas y de hoy, mantenimientos vencidos o próximos, pagos de la semana y patrimonio neto. Buen punto de partida antes de usar las demás herramientas.')]
#[IsReadOnly]
class GetSummary extends Tool
{
    public function handle(Request $request, ReportService $reports, AssetReport $assetReport, FinanceReport $finance): Response
    {
        $today = CarbonImmutable::today();
        $month = $reports->spending($today->startOfMonth(), $today->endOfDay());
        $lastMonth = $reports->spending($today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth());
        $assets = $assetReport->assets();
        $assetSummary = $assetReport->summary($assets);
        $plan = fn (MaintenancePlan $p) => ['asset' => $p->asset->name, 'plan' => $p->name, 'due' => $p->dueLabel(), 'task_id' => $p->task_id];

        return $this->json([
            'today' => $today->toDateString(),
            'groceries' => [
                'spent_this_month' => round($month['total'], 2),
                'spent_last_month' => round($lastMonth['total'], 2),
                'recent_lists' => ShoppingList::with(['items.price', 'items.product.prices'])
                    ->orderByDesc('shopped_on')->orderByDesc('id')->take(4)->get()
                    ->map(fn (ShoppingList $l) => Present::shoppingList($l))->all(),
            ],
            'meals_today' => MealPlanEntry::with('recipe.ingredients.product.prices')->whereDate('planned_on', $today)->orderBy('id')->get()
                ->map(fn (MealPlanEntry $e) => GetMenu::entry($e))->all(),
            'tasks_due' => Task::with('list')->dueBy($today)->orderBy('due_on')->orderByDesc('priority')->get()
                ->map(fn (Task $t) => Present::task($t))->all(),
            'maintenance' => [
                'overdue' => $assetSummary['overdue']->map($plan)->all(),
                'soon' => $assetSummary['soon']->map($plan)->all(),
            ],
            'payments_due_this_week' => RecurringPayment::active()
                ->where('next_due_on', '<', $today->addDays(RecurringPayment::SOON_DAYS + 1)->toDateString())
                ->orderBy('next_due_on')->get()
                ->map(fn (RecurringPayment $p) => [
                    'id' => $p->id, 'name' => $p->name, 'amount' => $p->amount,
                    'next_due_on' => $p->next_due_on->toDateString(), 'status' => $p->status(),
                ])->all(),
            'net_worth' => $finance->netWorthOn($today, null, $assets),
        ]);
    }
}
