<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Una receta completa: ingredientes (con sección, artículo o sub-receta ligada, cantidad y costo), pasos por etapa (con minutos), notas, etiquetas, calificación, costo y macros por porción. `ingredients` y `steps` vienen en el mismo formato que acepta update-recipe (quantity y unit sin escalar). Con `servings` se recalculan `amount` y costos para esas porciones.')]
#[IsReadOnly]
class GetRecipe extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'recipe_id' => ['required', 'integer'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:999'],
        ], [], ['servings' => 'porciones']);

        $recipe = $this->find(Recipe::class, $data['recipe_id'], 'una receta', [...Recipe::TREE, 'steps', 'tags', 'usedAsIngredient.recipe']);
        $servings = (float) ($data['servings'] ?? $recipe->servings);
        $factor = $recipe->factor($servings);
        $nutrition = $recipe->nutrition($servings);
        $stack = [$recipe->id];

        return $this->json([
            ...Present::recipe($recipe),
            'for_servings' => $servings,
            'cost' => $recipe->cost($servings),
            'ingredients' => $recipe->ingredients->map(fn (RecipeIngredient $i) => [
                'section' => $i->section,
                'name' => $i->displayName(),
                'product_id' => $i->product_id,
                'subrecipe_id' => $i->subrecipe_id,
                'quantity' => $i->quantity,
                'unit' => $i->unit?->value,
                'note' => $i->note,
                'amount' => $i->quantityLabel($factor) ?: null,
                'cost' => $i->cost($factor, $stack),
            ])->all(),
            'steps' => $recipe->steps->map(fn (RecipeStep $s) => ['stage' => $s->stage, 'text' => $s->body, 'minutes' => $s->minutes])->all(),
            'nutrition_per_serving' => $nutrition['perServing'] ?? null,
            'ingredients_without_nutrition' => $nutrition['missing'] ?? null,
            'grams_per_serving' => $recipe->gramsPerServing(),
            'used_in' => $recipe->usedAsIngredient->pluck('recipe.name')->filter()->unique()->values()->all(),
            'source_url' => $recipe->source_url,
            'notes' => $recipe->notes,
            'url' => route('recipes.show', $recipe),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'recipe_id' => $schema->integer()->description('Id de la receta (de list-recipes).')->required(),
            'servings' => $schema->integer()->description('Porciones a cocinar; por defecto las de la receta.'),
        ];
    }
}
