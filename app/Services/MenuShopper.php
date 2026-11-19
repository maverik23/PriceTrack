<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingList;

/**
 * Pasa los ingredientes de recetas (una sola o el menú de la semana) a una
 * lista de compras, sumando lo que se necesita de cada artículo.
 */
class MenuShopper
{
    /**
     * @param  iterable<int, array{0: Recipe, 1: float}>  $portions  receta + porciones a cocinar
     * @return array{needs: array<int, float>, unlinked: array<int, string>} needs: id del artículo → unidad base
     */
    public function needs(iterable $portions): array
    {
        $needs = [];
        $unlinked = [];

        foreach ($portions as [$recipe, $servings]) {
            $factor = $recipe->factor($servings);
            foreach ($recipe->ingredients as $ingredient) {
                /** @var RecipeIngredient $ingredient */
                $base = $ingredient->productBaseQty($factor);
                if ($base !== null) {
                    $needs[$ingredient->product_id] = ($needs[$ingredient->product_id] ?? 0) + $base;
                } else {
                    $unlinked[$ingredient->displayName()] = $ingredient->displayName();
                }
            }
        }

        return ['needs' => $needs, 'unlinked' => array_values($unlinked)];
    }

    /**
     * Suma las necesidades a la lista, en presentaciones habituales de cada artículo
     * (250 g de un artículo de 1 kg = 0.25). Devuelve cuántos artículos se tocaron.
     *
     * @param  array<int, float>  $needs
     */
    public function addToList(ShoppingList $list, array $needs): int
    {
        $products = Product::findMany(array_keys($needs))->keyBy('id');
        $count = 0;

        foreach ($needs as $productId => $base) {
            $product = $products->get($productId);
            if (! $product || $product->standardBaseQty() <= 0) {
                continue;
            }

            $item = $list->items()->firstOrNew(['product_id' => $productId]);
            $quantity = ($item->exists ? $item->quantity : 0) + $base / $product->standardBaseQty();
            $item->quantity = min(9999, max(0.0001, round($quantity, 4)));
            $item->save();
            $count++;
        }

        return $count;
    }

    /** La lista elegida o una nueva con ese nombre, para hoy. */
    public function resolveList(?int $listId, string $newName): ShoppingList
    {
        return $listId
            ? ShoppingList::findOrFail($listId)
            : ShoppingList::create(['name' => $newName, 'shopped_on' => today()]);
    }

    /** Mensaje para el usuario con lo que se agregó y lo que no se pudo. */
    public function summary(int $count, array $unlinked): string
    {
        $message = $count === 1 ? 'Se agregó 1 artículo a la lista.' : "Se agregaron {$count} artículos a la lista.";
        if ($unlinked) {
            $message .= ' Sin artículo ligado (agrégalos a mano): '.implode(', ', $unlinked).'.';
        }

        return $message;
    }
}
