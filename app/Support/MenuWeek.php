<?php

namespace App\Support;

use App\Models\MealDayNote;
use App\Models\MealPlanEntry;
use App\Models\Product;
use App\Models\Recipe;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * El menú de una semana (lunes a domingo) con su costo y sus macros por platillo, por comida
 * y por día. Lo usan la pantalla (MenuController) y la API de la app (Api\MenuController).
 */
class MenuWeek
{
    /** El lunes de la semana de ese día (cualquier día; si no es fecha, esta semana). */
    public static function start(mixed $day): CarbonImmutable
    {
        try {
            $date = $day ? CarbonImmutable::parse($day) : CarbonImmutable::today();
        } catch (\Throwable) {
            $date = CarbonImmutable::today();
        }

        return $date->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }

    /** @return Collection<int, MealPlanEntry> */
    public static function entries(CarbonImmutable $start): Collection
    {
        return MealPlanEntry::with([...MealPlanEntry::tree(), 'recipe.attachments'])
            // Rango semiabierto: con fecha y hora guardadas, whereBetween dejaría fuera el domingo
            ->where('planned_on', '>=', $start->toDateString())
            ->where('planned_on', '<', $start->addWeek()->toDateString())
            ->orderBy('planned_on')->orderBy('id')
            ->get();
    }

    /**
     * Los días de la semana con sus comidas. Costo (de las porciones planeadas) y macros (de
     * una porción: lo que come una persona) por platillo, por comida y por día; `partial` =
     * algún platillo sin datos (el total es aproximado).
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, entries: Collection, days: Collection, notes: Collection, total: float, avgKcal: ?float}
     */
    public static function summary(CarbonImmutable $start): array
    {
        $entries = self::entries($start);
        $cells = $entries->mapWithKeys(fn (MealPlanEntry $e) => [$e->id => [
            'entry' => $e,
            'cost' => $e->cost(),
            'macros' => $e->macrosPerServing(),
            'photo' => $e->recipe?->coverPhoto()?->url(true),
        ]]);
        $sum = function (Collection $items): array {
            $withMacros = $items->filter(fn ($i) => $i['macros']);

            return [
                'cost' => round($items->sum(fn ($i) => $i['cost'] ?? 0), 2),
                'macros' => $withMacros->isEmpty() ? null : collect(array_keys(Product::NUTRIENTS))
                    ->mapWithKeys(fn ($k) => [$k => round($withMacros->sum(fn ($i) => $i['macros'][$k]), 1)])->all(),
                'partial' => $items->contains(fn ($i) => ! $i['macros'] || $i['macros']['missing']),
            ];
        };

        $days = collect(range(0, 6))->map(function (int $offset) use ($start, $cells, $sum) {
            $date = $start->addDays($offset);
            $ofDay = $cells->filter(fn ($c) => $c['entry']->planned_on->isSameDay($date));
            $meals = collect(MealPlanEntry::MEALS)->map(function ($label, $meal) use ($ofDay, $sum) {
                $items = $ofDay->filter(fn ($c) => $c['entry']->meal === $meal)->values();

                return ['items' => $items] + $sum($items);
            });

            return ['date' => $date, 'meals' => $meals, 'count' => $ofDay->count()] + $sum($ofDay->values());
        });
        $planned = $days->filter(fn ($d) => $d['macros']);

        return [
            'start' => $start,
            'end' => $start->addDays(6),
            'entries' => $entries,
            'days' => $days,
            'notes' => MealDayNote::where('planned_on', '>=', $start->toDateString())
                ->where('planned_on', '<', $start->addWeek()->toDateString())
                ->get()->keyBy(fn (MealDayNote $n) => $n->planned_on->toDateString()),
            'total' => round($days->sum('cost'), 2),
            // Promedio por persona en los días con algo planeado
            'avgKcal' => $planned->isEmpty() ? null : round($planned->avg(fn ($d) => $d['macros']['kcal'])),
        ];
    }

    /**
     * Las recetas de la semana con sus porciones, para mandar sus ingredientes a una lista.
     *
     * @return Collection<int, array{0: Recipe, 1: float}>
     */
    public static function portions(CarbonImmutable $start): Collection
    {
        return self::entries($start)
            ->filter(fn (MealPlanEntry $e) => $e->recipe)
            ->map(fn (MealPlanEntry $e) => [$e->recipe->loadShoppingTree(), (float) $e->plannedServings()]);
    }

    /** "Menú del 16 nov al 22 nov": nombre de la lista nueva. */
    public static function listName(CarbonImmutable $start): string
    {
        return 'Menú del '.$start->translatedFormat('d M').' al '.$start->addDays(6)->translatedFormat('d M');
    }
}
