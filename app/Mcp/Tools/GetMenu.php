<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\MealDayNote;
use App\Models\MealPlanEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Menú de una semana (lunes a domingo): qué se come cada día en desayuno, comida, merienda, cena y otro, con porciones y costo estimado, y la nota de cada día (`note`, p. ej. "Mealprep: cocer arroz"). `week` acepta cualquier día de esa semana; sin él, la semana actual.')]
#[IsReadOnly]
class GetMenu extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate(['week' => ['nullable', 'date']], [], ['week' => 'semana']);
        $start = self::weekStart($this->date($data['week'] ?? null));
        $entries = self::weekEntries($start);
        $notes = MealDayNote::where('planned_on', '>=', $start->toDateString())
            ->where('planned_on', '<', $start->addWeek()->toDateString())->get()
            ->keyBy(fn (MealDayNote $n) => $n->planned_on->toDateString());

        return $this->json([
            'week_start' => $start->toDateString(),
            'week_end' => $start->addDays(6)->toDateString(),
            'estimated_cost' => round($entries->sum(fn (MealPlanEntry $e) => $e->cost() ?? 0), 2),
            'days' => collect(range(0, 6))->map(function (int $offset) use ($start, $entries, $notes) {
                $date = $start->addDays($offset);

                return [
                    'date' => $date->toDateString(),
                    'day' => $date->translatedFormat('l'),
                    'note' => $notes->get($date->toDateString())?->body,
                    'meals' => $entries->filter(fn (MealPlanEntry $e) => $e->planned_on->isSameDay($date))
                        ->map(fn (MealPlanEntry $e) => self::entry($e))->values()->all(),
                ];
            })->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['week' => $schema->string()->description('Cualquier día de la semana, AAAA-MM-DD.')];
    }

    public static function weekStart(?CarbonImmutable $day): CarbonImmutable
    {
        return ($day ?? CarbonImmutable::today())->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }

    /** @return Collection<int, MealPlanEntry> */
    public static function weekEntries(CarbonImmutable $start): Collection
    {
        return MealPlanEntry::with(MealPlanEntry::tree())
            // Rango semiabierto: la fecha se guarda con hora
            ->where('planned_on', '>=', $start->toDateString())
            ->where('planned_on', '<', $start->addWeek()->toDateString())
            ->orderBy('planned_on')->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    public static function entry(MealPlanEntry $entry): array
    {
        return [
            'entry_id' => $entry->id,
            'date' => $entry->planned_on->toDateString(),
            'meal' => $entry->meal,
            'meal_label' => $entry->mealLabel(),
            'what' => $entry->label(),
            'recipe_id' => $entry->recipe_id,
            'servings' => $entry->plannedServings(),
            'cost' => $entry->cost(),
        ];
    }
}
