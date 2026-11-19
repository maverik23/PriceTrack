<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Mcp\Tools\Concerns\RecipeSchema;
use App\Models\Recipe;
use App\Support\RecipeData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Corrige una receta existente: manda solo los campos que cambian; lo demás se conserva. `ingredients`, `steps` (o `instructions`) y `tags`, si se mandan, **reemplazan la lista completa**: lee antes la receta con get-recipe (trae `ingredients` y `steps` en el mismo formato) y devuelve la lista entera con los cambios. Sirve para pasar las secciones que quedaron en la nota de un ingrediente al campo `section`, partir los pasos en etapas o cambiar un artículo "Casero" por su receta (`subrecipe_id`; para hacerlo en todas las recetas a la vez usa use-recipe-as-ingredient). Unidades: g, kg, ml, l, item, cup, tbsp, tsp, serving (porciones, solo sub-recetas).')]
class UpdateRecipe extends Tool
{
    use RecipeSchema;

    public function handle(Request $request): Response
    {
        $recipe = $this->find(Recipe::class, $request->get('recipe_id'), 'una receta');

        $input = RecipeData::toInput($recipe);
        $changes = collect($request->all())->except('recipe_id')->all();
        if (array_key_exists('instructions', $changes)) {
            // El texto reemplaza los pasos
            unset($input['steps']);
        }

        RecipeData::save($recipe, RecipeData::validate([...$input, ...$changes], $recipe));

        return $this->json([
            'updated' => Present::recipe($recipe->loadShoppingTree()->load('tags')),
            'ingredients_without_cost' => $recipe->uncostedCount(),
            'url' => route('recipes.show', $recipe),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'recipe_id' => $schema->integer()->description('Id de la receta (de list-recipes).')->required(),
            ...$this->recipeFields($schema),
        ];
    }
}
