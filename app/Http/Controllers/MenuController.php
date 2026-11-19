<?php

namespace App\Http\Controllers;

use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Services\MenuShopper;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class MenuController extends Controller
{
    /** Menú de una semana (lunes a domingo); ?week= acepta cualquier día de esa semana. */
    public function index(Request $request)
    {
        $start = $this->weekStart($request->query('week'));
        $entries = $this->weekEntries($start);

        $days = collect(range(0, 6))->map(function (int $offset) use ($start, $entries) {
            $date = $start->addDays($offset);
            $ofDay = $entries->filter(fn (MealPlanEntry $e) => $e->planned_on->isSameDay($date));

            return [
                'date' => $date,
                'meals' => collect(MealPlanEntry::MEALS)->map(fn ($label, $meal) => $ofDay->where('meal', $meal)->values()),
            ];
        });

        return view('menu.index', [
            'start' => $start,
            'end' => $start->addDays(6),
            'days' => $days,
            'entries' => $entries,
            'total' => round($entries->sum(fn (MealPlanEntry $e) => $e->cost() ?? 0), 2),
            'meals' => MealPlanEntry::MEALS,
            'recipes' => Recipe::orderBy('name')->get(['id', 'name', 'servings']),
            'lists' => ShoppingList::orderByDesc('shopped_on')->orderByDesc('id')->take(10)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'planned_on' => ['required', 'date'],
            'meal' => ['required', Rule::in(array_keys(MealPlanEntry::MEALS))],
            'recipe_id' => ['nullable', 'required_without:title', 'exists:recipes,id'],
            'title' => ['nullable', 'required_without:recipe_id', 'string', 'max:150'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:999'],
        ], [
            'recipe_id.required_without' => 'Elige una receta o escribe qué se va a comer.',
            'title.required_without' => 'Elige una receta o escribe qué se va a comer.',
        ], ['planned_on' => 'día', 'meal' => 'comida', 'servings' => 'porciones']);

        if (! empty($data['recipe_id'])) {
            $data['title'] = null;
        } else {
            $data['servings'] = null;
        }

        $entry = MealPlanEntry::create($data);

        return redirect()->route('menu.index', ['week' => $this->weekStart($entry->planned_on)->toDateString()])
            ->with('success', 'Agregado al menú.');
    }

    public function destroy(MealPlanEntry $entry)
    {
        $entry->delete();

        return redirect()->route('menu.index', ['week' => $this->weekStart($entry->planned_on)->toDateString()])
            ->with('success', 'Quitado del menú.');
    }

    /** Revisión antes de mandar el menú de la semana a una lista: qué falta y qué ya está en casa. */
    public function shopReview(Request $request, MenuShopper $shopper)
    {
        $start = $this->weekStart($request->query('week'));
        $result = $shopper->needs($this->weekPortions($start));

        return view('recipes.shop', [
            'title' => 'Menú de la semana',
            'subtitle' => 'Del '.$start->translatedFormat('d M').' al '.$start->addDays(6)->translatedFormat('d M'),
            'back' => route('menu.index', ['week' => $start->toDateString()]),
            'action' => route('menu.shop'),
            'hidden' => ['week' => $start->toDateString()],
            'newList' => $this->listName($start),
            'rows' => $shopper->reviewRows($result),
            'unlinked' => $result['unlinked'],
            'lists' => ShoppingList::orderByDesc('shopped_on')->orderByDesc('id')->take(10)->get(),
        ]);
    }

    /** Manda a una lista de compras los ingredientes de todas las recetas de la semana. */
    public function shop(Request $request, MenuShopper $shopper)
    {
        $data = $request->validate([
            'week' => ['required', 'date'],
            'list_id' => ['nullable', 'exists:shopping_lists,id'],
            'buy' => ['nullable', 'array'],
            'buy.*' => ['integer'],
        ]);

        $start = $this->weekStart($data['week']);
        $result = $shopper->needs($this->weekPortions($start));
        if (! $result['rows']) {
            return back()->withErrors(['list_id' => 'El menú de esta semana no tiene recetas con ingredientes ligados a artículos.']);
        }

        [$add, $skipped] = $shopper->chosen($result, $request->boolean('reviewed') ? ($data['buy'] ?? []) : null);
        if (! $add) {
            return redirect()->route('menu.index', ['week' => $start->toDateString()])->with('success', $shopper->summary(0, $skipped, $result['unlinked']));
        }

        $list = $shopper->resolveList($data['list_id'] ?? null, $this->listName($start));
        $count = $shopper->addToList($list, $add);

        return redirect()->route('lists.show', $list)->with('success', $shopper->summary($count, $skipped, $result['unlinked']));
    }

    /** @return Collection<int, array{0: Recipe, 1: float}> */
    private function weekPortions(CarbonImmutable $start)
    {
        return $this->weekEntries($start)
            ->filter(fn (MealPlanEntry $e) => $e->recipe)
            ->map(fn (MealPlanEntry $e) => [$e->recipe->loadShoppingTree(), (float) $e->plannedServings()]);
    }

    private function listName(CarbonImmutable $start): string
    {
        return 'Menú del '.$start->translatedFormat('d M').' al '.$start->addDays(6)->translatedFormat('d M');
    }

    private function weekStart(mixed $day): CarbonImmutable
    {
        try {
            $date = $day ? CarbonImmutable::parse($day) : CarbonImmutable::today();
        } catch (\Throwable) {
            $date = CarbonImmutable::today();
        }

        return $date->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }

    private function weekEntries(CarbonImmutable $start)
    {
        return MealPlanEntry::with('recipe.ingredients.product.prices')
            // Rango semiabierto: con fecha y hora guardadas, whereBetween dejaría fuera el domingo
            ->where('planned_on', '>=', $start->toDateString())
            ->where('planned_on', '<', $start->addWeek()->toDateString())
            ->orderBy('planned_on')->orderBy('id')
            ->get();
    }
}
