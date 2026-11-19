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

#[Description('Manda a una lista de compras los ingredientes de una receta (`recipe_id`, con porciones opcionales) o de todo el menú de una semana (`week`). Las cantidades se suman por artículo y se **agregan** a lo que ya tenga la lista: si se manda dos veces, se duplica. Sin `list_id` crea una lista nueva para hoy. Las sub-recetas se expanden en sus ingredientes. **Despensa**: lo que está en casa no se agrega (`already_have`); si la despensa dice cuánto queda, solo se agrega lo que falta. Con `include_pantry: true` se agrega todo. Con `preview: true` solo devuelve lo que se agregaría (por artículo: lo que se necesita, lo que hay en casa y lo que falta), sin tocar nada: úsalo para revisar con la persona y corregir la despensa con update-pantry antes de mandar. Los ingredientes sin artículo ligado no se agregan y se devuelven en `not_added`.')]
class AddIngredientsToList extends Tool
{
    public function handle(Request $request, MenuShopper $shopper): Response
    {
        $data = $request->validate([
            'recipe_id' => ['nullable', 'required_without:week', 'prohibits:week', 'integer', 'exists:recipes,id'],
            'week' => ['nullable', 'required_without:recipe_id', 'date'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:999'],
            'list_id' => ['nullable', 'integer', 'exists:shopping_lists,id'],
            'include_pantry' => ['nullable', 'boolean'],
            'preview' => ['nullable', 'boolean'],
        ], [], ['recipe_id' => 'receta', 'week' => 'semana', 'servings' => 'porciones', 'list_id' => 'lista']);

        if (isset($data['recipe_id'])) {
            $recipe = Recipe::findOrFail($data['recipe_id'])->loadShoppingTree();
            $portions = [[$recipe, (float) ($data['servings'] ?? $recipe->servings)]];
            $name = $recipe->name;
        } else {
            $start = GetMenu::weekStart($this->date($data['week']));
            $portions = GetMenu::weekEntries($start)
                ->filter(fn (MealPlanEntry $e) => $e->recipe)
                ->map(fn (MealPlanEntry $e) => [$e->recipe->loadShoppingTree(), (float) $e->plannedServings()]);
            $name = 'Menú del '.$start->translatedFormat('d M').' al '.$start->addDays(6)->translatedFormat('d M');
        }

        $result = $shopper->needs($portions);
        if (! $result['rows']) {
            return Response::error('No hay ingredientes ligados a artículos con cantidad; no se agregó nada.');
        }

        $add = ! empty($data['include_pantry']) ? $result['needs'] : $result['buy'];
        $label = fn (int $id, float $base) => $result['rows'][$id]['product']->baseLabel($base);
        $rows = collect($result['rows'])->map(fn (array $row, int $id) => [
            'product_id' => $id,
            'name' => $row['product']->fullName(),
            'need' => $label($id, $row['need']),
            'in_pantry' => $row['have'] > 0 ? ($row['product']->pantry_qty === null ? 'suficiente' : $label($id, $row['have'])) : null,
            'to_add' => isset($add[$id]) ? $label($id, $add[$id]) : null,
            'estimate' => isset($add[$id]) ? $row['product']->cheapestCostFor($add[$id]) : null,
        ])->values();

        if (! empty($data['preview'])) {
            return $this->json([
                'preview' => true,
                'items' => $rows->all(),
                'estimated_total' => round($rows->sum('estimate'), 2),
                'not_added' => $result['unlinked'],
            ]);
        }

        if (! $add) {
            return $this->json(['added' => 0, 'already_have' => $result['pantry'], 'not_added' => $result['unlinked'], 'message' => 'Todo está en la despensa; no se agregó nada.']);
        }

        $list = $shopper->resolveList($data['list_id'] ?? null, $name);
        $count = $shopper->addToList($list, $add);

        return $this->json([
            'added' => $count,
            'items' => $rows->whereNotNull('to_add')->values()->all(),
            'already_have' => ! empty($data['include_pantry']) ? [] : $result['pantry'],
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
            'include_pantry' => $schema->boolean()->description('true = agregar también lo que ya está en la despensa.'),
            'preview' => $schema->boolean()->description('true = solo mostrar lo que se agregaría, sin agregar nada.'),
        ];
    }
}
