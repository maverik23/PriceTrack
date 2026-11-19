<?php

namespace App\Mcp\Tools;

use App\Enums\IngredientUnit;
use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Recipe;
use App\Support\RecipeData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Guarda una receta nueva. Cada ingrediente es un artículo del súper (`product_id` con `quantity` y `unit` obligatorios: de ahí salen el costo, la lista de compras y los macros) o texto libre (`name`, p. ej. "Sal", con cantidad opcional). Busca los artículos con search-products antes de ligarlos. Unidades: g, kg, ml, l, item (piezas), cup (taza), tbsp (cucharada), tsp (cucharadita). `instructions` lleva un paso por línea.')]
class CreateRecipe extends Tool
{
    public function handle(Request $request): Response
    {
        [$data, $ingredients] = RecipeData::validate($request->all());

        $recipe = DB::transaction(function () use ($data, $ingredients) {
            $recipe = Recipe::create($data);
            $recipe->ingredients()->createMany($ingredients);

            return $recipe;
        });

        return $this->json([
            'created' => Present::recipe($recipe->load('ingredients.product.prices')),
            'ingredients_without_cost' => $recipe->uncostedCount(),
            'url' => route('recipes.show', $recipe),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Nombre de la receta.')->required(),
            'servings' => $schema->integer()->description('Porciones que rinde.')->required(),
            'category' => $schema->string()->description('Categoría (Desayunos, Postres…).'),
            'minutes' => $schema->integer()->description('Tiempo de preparación en minutos.'),
            'instructions' => $schema->string()->description('Preparación, un paso por línea.'),
            'source_url' => $schema->string()->description('Dirección de donde salió la receta.'),
            'notes' => $schema->string(),
            'ingredients' => $schema->array()->items($schema->object([
                'product_id' => $schema->integer()->description('Id del artículo del súper (opcional).'),
                'name' => $schema->string()->description('Nombre, si no se liga a un artículo.'),
                'quantity' => $schema->number(),
                'unit' => $schema->string()->enum(array_column(IngredientUnit::cases(), 'value')),
                'note' => $schema->string()->description('"picada", "al gusto"…'),
            ]))->description('Ingredientes en orden.'),
        ];
    }
}
