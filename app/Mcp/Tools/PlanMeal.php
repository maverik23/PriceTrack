<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\MealPlanEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Agrega una comida al menú: una receta guardada (`recipe_id`, con porciones opcionales) o texto libre (`title`: "Sobras", "Comer fuera"). Para una nota del día ("Mealprep: cocer arroz y pollo") usa set-menu-note.')]
class PlanMeal extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'planned_on' => ['required', 'date'],
            'meal' => ['required', Rule::in(array_keys(MealPlanEntry::MEALS))],
            'recipe_id' => ['nullable', 'required_without:title', 'integer', 'exists:recipes,id'],
            'title' => ['nullable', 'string', 'max:150'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:999'],
        ], [
            'recipe_id.required_without' => 'Elige una receta (recipe_id) o escribe qué se va a comer (title).',
        ], ['planned_on' => 'día', 'meal' => 'comida', 'servings' => 'porciones']);

        if (! empty($data['recipe_id'])) {
            $data['title'] = null;
        } else {
            $data['servings'] = null;
        }

        $entry = MealPlanEntry::create($data);

        return $this->json(['planned' => GetMenu::entry($entry->load(MealPlanEntry::tree()))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'planned_on' => $schema->string()->description('Día, AAAA-MM-DD.')->required(),
            'meal' => $schema->string()->enum(array_keys(MealPlanEntry::MEALS))->description('breakfast (desayuno), lunch (comida), snack (merienda), dinner (cena) u other.')->required(),
            'recipe_id' => $schema->integer()->description('Id de la receta.'),
            'title' => $schema->string()->description('Qué se come, si no es una receta guardada.'),
            'servings' => $schema->integer()->description('Porciones (por defecto las de la receta).'),
        ];
    }
}
