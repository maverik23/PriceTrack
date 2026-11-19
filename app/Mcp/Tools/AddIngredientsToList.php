<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Services\MenuShopper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Manda a una lista de compras los ingredientes de una receta (`recipe_id`, con porciones opcionales) o de todo el menú de una semana (`week`). Las cantidades se suman por artículo y se **agregan** a lo que ya tenga la lista: si se manda dos veces, se duplica. Sin `list_id` crea una lista nueva para hoy. Los ingredientes sin artículo ligado no se agregan y se devuelven en `not_added`.')]
class AddIngredientsToList extends Tool
{
    public function handle(Request $request, MenuShopper $shopper): Response
    {
        $data = $request->validate([
            'recipe_id' => ['nullable', 'required_without:week', 'prohibits:week', 'integer', 'exists:recipes,id'],
            'week' => ['nullable', 'required_without:recipe_id', 'date'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:999'],
            'list_id' => ['nullable', 'integer', 'exists:shopping_lists,id'],
        ], [], ['recipe_id' => 'receta', 'week' => 'semana', 'servings' => 'porciones', 'list_id' => 'lista']);

        if (isset($data['recipe_id'])) {
            $recipe = Recipe::with('ingredients.product')->findOrFail($data['recipe_id']);
            $portions = [[$recipe, (float) ($data['servings'] ?? $recipe->servings)]];
            $name = $recipe->name;
        } else {
            $start = GetMenu::weekStart($this->date($data['week']));
            $portions = GetMenu::weekEntries($start)
                ->filter(fn (MealPlanEntry $e) => $e->recipe)
                ->map(fn (MealPlanEntry $e) => [$e->recipe, (float) $e->plannedServings()]);
            $name = 'Menú del '.$start->translatedFormat('d M').' al '.$start->addDays(6)->translatedFormat('d M');
        }

        $result = $shopper->needs($portions);
        if (! $result['needs']) {
            return Response::error('No hay ingredientes ligados a artículos con cantidad; no se agregó nada.');
        }

        $list = $shopper->resolveList($data['list_id'] ?? null, $name);
        $count = $shopper->addToList($list, $result['needs']);

        return $this->json([
            'added' => $count,
            'not_added' => $result['unlinked'],
            'list' => Present::shoppingList($list->load(['items.price', 'items.product.prices'])),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'recipe_id' => $schema->integer()->description('Receta cuyos ingredientes se mandan (o usa week).'),
            'servings' => $schema->integer()->description('Porciones a cocinar de esa receta.'),
            'week' => $schema->string()->description('Cualquier día de la semana del menú, AAAA-MM-DD (o usa recipe_id).'),
            'list_id' => $schema->integer()->description('Lista a la que se agregan; sin ella se crea una nueva.'),
        ];
    }
}
