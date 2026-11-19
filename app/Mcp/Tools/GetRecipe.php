<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Una receta completa: ingredientes (cantidad, artículo ligado y costo), preparación, costo y macros por porción. Con `servings` se recalculan cantidades y costo para esas porciones.')]
#[IsReadOnly]
class GetRecipe extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'recipe_id' => ['required', 'integer'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:999'],
        ], [], ['servings' => 'porciones']);

        $recipe = $this->find(Recipe::class, $data['recipe_id'], 'una receta', ['ingredients.product.prices']);
        $servings = (float) ($data['servings'] ?? $recipe->servings);
        $factor = $recipe->factor($servings);
        $nutrition = $recipe->nutrition($servings);

        return $this->json([
            ...Present::recipe($recipe),
            'for_servings' => $servings,
            'cost' => $recipe->cost($servings),
            'ingredients' => $recipe->ingredients->map(fn (RecipeIngredient $i) => [
                'name' => $i->displayName(),
                'quantity' => $i->quantityLabel($factor) ?: null,
                'note' => $i->note,
                'product_id' => $i->product_id,
                'cost' => $i->cost($factor),
            ])->all(),
            'steps' => $recipe->steps(),
            'nutrition_per_serving' => $nutrition['perServing'] ?? null,
            'ingredients_without_nutrition' => $nutrition['missing'] ?? null,
            'grams_per_serving' => $recipe->gramsPerServing(),
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
