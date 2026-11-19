<?php

namespace App\Http\Controllers;

use App\Models\MealDayNote;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Services\MenuShopper;
use App\Support\MealPlanData;
use App\Support\MenuWeek;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    /** Menú de una semana (lunes a domingo); ?week= acepta cualquier día de esa semana. */
    public function index(Request $request)
    {
        $week = MenuWeek::summary(MenuWeek::start($request->query('week')));

        return view('menu.index', [
            'notes' => $week['notes'],
            'start' => $week['start'],
            'end' => $week['end'],
            'days' => $week['days'],
            'entries' => $week['entries'],
            'total' => $week['total'],
            'avgKcal' => $week['avgKcal'],
            'meals' => MealPlanEntry::MEALS,
            'recipes' => Recipe::orderBy('name')->get(['id', 'name', 'servings']),
            'lists' => ShoppingList::pending()->orderByDesc('shopped_on')->orderByDesc('id')->take(10)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $entry = MealPlanEntry::create(MealPlanData::validate($request->all()));

        return redirect()->route('menu.index', ['week' => MenuWeek::start($entry->planned_on)->toDateString()])
            ->with('success', 'Agregado al menú.');
    }

    /** Nota del día ("Mealprep: cocer arroz y pollo"); vacía la borra. */
    public function note(Request $request, string $date)
    {
        $data = $request->validate(['body' => ['nullable', 'string', 'max:500']], [], ['body' => 'nota']);
        $note = MealDayNote::put($date, $data['body'] ?? null);

        if ($request->expectsJson()) {
            return response()->json(['body' => $note?->body]);
        }

        return redirect()->route('menu.index', ['week' => MenuWeek::start($date)->toDateString()])
            ->with('success', $note ? 'Nota del día guardada.' : 'Nota del día borrada.');
    }

    public function destroy(MealPlanEntry $entry)
    {
        $entry->delete();

        return redirect()->route('menu.index', ['week' => MenuWeek::start($entry->planned_on)->toDateString()])
            ->with('success', 'Quitado del menú.');
    }

    /** Revisión antes de mandar el menú de la semana a una lista: qué falta y qué ya está en casa. */
    public function shopReview(Request $request, MenuShopper $shopper)
    {
        $start = MenuWeek::start($request->query('week'));
        $result = $shopper->needs(MenuWeek::portions($start));

        return view('recipes.shop', [
            'title' => 'Menú de la semana',
            'subtitle' => 'Del '.$start->translatedFormat('d M').' al '.$start->addDays(6)->translatedFormat('d M'),
            'back' => route('menu.index', ['week' => $start->toDateString()]),
            'action' => route('menu.shop'),
            'hidden' => ['week' => $start->toDateString()],
            'newList' => MenuWeek::listName($start),
            'rows' => $shopper->reviewRows($result),
            'unlinked' => $result['unlinked'],
            'lists' => ShoppingList::pending()->orderByDesc('shopped_on')->orderByDesc('id')->take(10)->get(),
        ]);
    }

    /** Manda a una lista de compras los ingredientes de todas las recetas de la semana. */
    public function shop(Request $request, MenuShopper $shopper)
    {
        $data = $request->validate(MenuShopper::RULES + ['week' => ['required', 'date']]);
        $start = MenuWeek::start($data['week']);

        $sent = $shopper->send(MenuWeek::portions($start), $data['list_id'] ?? null, MenuWeek::listName($start), $request->boolean('reviewed') ? ($data['buy'] ?? []) : null);
        if ($sent['empty']) {
            return back()->withErrors(['list_id' => 'El menú de esta semana no tiene recetas con ingredientes ligados a artículos.']);
        }

        return ($sent['list'] ? redirect()->route('lists.show', $sent['list']) : redirect()->route('menu.index', ['week' => $start->toDateString()]))
            ->with('success', $sent['message']);
    }
}
