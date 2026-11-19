<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Product;
use App\Models\Recipe;
use App\Services\SubrecipeConverter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Cambia un artículo que en realidad es algo casero (p. ej. "Crema de verduras" o "Ciabatta" de categoría "Casero", sin precio) por su receta en **todas** las recetas que lo usan: el ingrediente pasa a ser la sub-receta y su costo, macros y compras salen de ella. La cantidad se conserva si está en la dimensión de lo que rinde la receta (dale `yield_qty`/`yield_unit` con update-recipe antes) o con las conversiones del artículo; las piezas pasan a porciones. Lo que no se puede convertir se queda igual y se informa en `skipped`. El artículo no se borra.')]
class UseRecipeAsIngredient extends Tool
{
    public function handle(Request $request, SubrecipeConverter $converter): Response
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'recipe_id' => ['required', 'integer'],
        ]);
        $product = $this->find(Product::class, $data['product_id'], 'un artículo');
        $recipe = $this->find(Recipe::class, $data['recipe_id'], 'una receta');

        return $this->json($converter->convert($product, $recipe) + ['product_still_exists' => true]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->description('El artículo casero que se reemplaza.')->required(),
            'recipe_id' => $schema->integer()->description('La receta que lo prepara.')->required(),
        ];
    }
}
