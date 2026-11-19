<?php

namespace App\Http\Controllers\Api;

use App\Api\Present;
use App\Models\MealPlanEntry;
use App\Services\MenuShopper;
use App\Support\MealPlanData;
use App\Support\MenuWeek;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Menú semanal para la app móvil (los mismos cálculos que la pantalla: MenuWeek). */
class MenuController extends Controller
{
    /**
     * La semana (lunes a domingo; `week` = cualquier día de ella): por día y comida, cada
     * platillo con su costo (porciones planeadas) y macros de una porción, con totales.
     */
    public function index(Request $request)
    {
        return response()->json(['data' => self::week(MenuWeek::start($request->query('week')))]);
    }

    /** `planned_on`, `meal` y `recipe_id` (con `servings`) o `title` (texto libre) → `201` con la semana. */
    public function store(Request $request)
    {
        $entry = MealPlanEntry::create(MealPlanData::validate($request->all()));

        return response()->json([
            'message' => 'Agregado al menú.',
            'id' => $entry->id,
            'data' => self::week(MenuWeek::start($entry->planned_on)),
        ], 201);
    }

    public function destroy(MealPlanEntry $entry)
    {
        $entry->delete();

        return response()->json(['message' => 'Quitado del menú.', 'data' => self::week(MenuWeek::start($entry->planned_on))]);
    }

    /** Revisión antes de mandar a una lista los ingredientes de las recetas de la semana. */
    public function shopReview(Request $request, MenuShopper $shopper)
    {
        $start = MenuWeek::start($request->query('week'));
        $result = $shopper->needs(MenuWeek::portions($start));

        return response()->json(['data' => RecipeController::review($shopper, $result, MenuWeek::listName($start)) + ['week' => $start->toDateString()]]);
    }

    /** Como en una receta: `week`, `list_id?`, `reviewed` + `buy[]`. */
    public function shop(Request $request, MenuShopper $shopper)
    {
        $data = $request->validate(MenuShopper::RULES + ['week' => ['required', 'date']], [], ['list_id' => 'lista', 'week' => 'semana']);
        $start = MenuWeek::start($data['week']);

        return RecipeController::sent(
            $shopper->send(MenuWeek::portions($start), $data['list_id'] ?? null, MenuWeek::listName($start), $request->boolean('reviewed') ? ($data['buy'] ?? []) : null),
            'El menú de esta semana no tiene recetas con ingredientes ligados a artículos.',
        );
    }

    /** @return array<string, mixed> */
    public static function week(CarbonImmutable $start): array
    {
        $week = MenuWeek::summary($start);

        return [
            'week_start' => $start->toDateString(),
            'week_end' => $week['end']->toDateString(),
            'prev' => $start->subWeek()->toDateString(),
            'next' => $start->addWeek()->toDateString(),
            'total' => $week['total'],
            'avg_kcal' => $week['avgKcal'],
            'meals' => MealPlanEntry::MEALS,
            'days' => $week['days']->map(fn (array $day) => [
                'date' => $day['date']->toDateString(),
                'day' => $day['date']->translatedFormat('l'),
                'note' => $week['notes']->get($day['date']->toDateString())?->body,
                'count' => $day['count'],
                'cost' => $day['cost'],
                'macros' => $day['macros'],
                'partial' => $day['partial'],
                'meals' => $day['meals']->map(fn (array $meal, string $key) => [
                    'meal' => $key,
                    'label' => MealPlanEntry::MEALS[$key],
                    'cost' => $meal['cost'],
                    'macros' => $meal['macros'],
                    'partial' => $meal['partial'],
                    'items' => $meal['items']->map(fn (array $cell) => Present::mealEntry($cell))->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
