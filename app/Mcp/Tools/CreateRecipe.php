<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Mcp\Tools\Concerns\RecipeSchema;
use App\Models\Recipe;
use App\Support\Categories;
use App\Support\RecipeData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Guarda una receta nueva (busca antes con list-recipes para no duplicarla). Cada ingrediente es un artículo del súper (`product_id` con `quantity` y `unit` obligatorios: de ahí salen el costo, la lista de compras y los macros; búscalo con search-products), otra receta (`subrecipe_id`: una sub-receta casera como una crema o un pan, en porciones o en g/ml si esa receta dice cuánto rinde; no uses un artículo "Casero" para eso) o texto libre (`name`, p. ej. "Sal", con cantidad opcional). Los ingredientes pueden llevar `section` ("Salsa", "Para servir") y los pasos `stage` (etapa) y `minutes` (temporizador). Unidades: g, kg, ml, l, item (piezas), cup (taza, 240 ml), tbsp (cucharada), tsp (cucharadita), serving (porciones, solo sub-recetas).')]
class CreateRecipe extends Tool
{
    use RecipeSchema;

    public function handle(Request $request): Response
    {
        $recipe = RecipeData::save(new Recipe, RecipeData::validate($request->all()));

        return $this->json([
            'created' => Present::recipe($recipe->loadShoppingTree()->load('tags')),
            'ingredients_without_cost' => $recipe->uncostedCount(),
            'steps' => $recipe->steps()->count(),
            'url' => route('recipes.show', $recipe),
        ], Categories::notices($request->all(), ['category' => [$recipe->category, 'recipe_category']]));
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = $this->recipeFields($schema);
        $fields['name'] = $fields['name']->required();
        $fields['servings'] = $fields['servings']->required();

        return $fields;
    }
}
