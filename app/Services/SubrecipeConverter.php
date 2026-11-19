<?php

namespace App\Services;

use App\Enums\IngredientUnit;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Support\Facades\DB;

/**
 * Cambia un artículo que en realidad es algo casero (crema de verduras, ciabatta) por
 * su receta en todas las recetas que lo usan. La cantidad se conserva cuando se puede:
 *
 * - en la misma dimensión que lo que rinde la receta (g ↔ g, taza ↔ ml), tal cual;
 * - por las conversiones del artículo (1 taza de un artículo en g, con el peso de la taza)
 *   a la dimensión de lo que rinde;
 * - piezas de un artículo que se mide en piezas = porciones de la receta (1 ciabatta = 1 porción).
 *
 * Lo que no se puede convertir se queda como estaba y se informa.
 */
class SubrecipeConverter
{
    /**
     * @return array{converted: array<int, string>, skipped: array<int, string>}
     */
    public function convert(Product $product, Recipe $recipe): array
    {
        $converted = [];
        $skipped = [];

        DB::transaction(function () use ($product, $recipe, &$converted, &$skipped) {
            $ingredients = RecipeIngredient::with('recipe')->where('product_id', $product->id)->get();

            foreach ($ingredients as $ingredient) {
                $owner = $ingredient->recipe;
                if ($owner->id === $recipe->id || in_array($owner->id, Recipe::descendantIds($recipe->id), true)) {
                    $skipped[] = "{$owner->name} (la receta «{$recipe->name}» ya la usa)";

                    continue;
                }

                $amount = $this->amount($ingredient, $product, $recipe);
                if (! $amount) {
                    $skipped[] = "{$owner->name} ({$ingredient->quantityLabel()}: ".($recipe->yieldDimension()
                        ? 'no se puede pasar a lo que rinde la receta'
                        : 'agrega a la receta cuánto rinde en g o ml').')';

                    continue;
                }

                $ingredient->update([
                    'product_id' => null,
                    'subrecipe_id' => $recipe->id,
                    'name' => $recipe->name,
                    'quantity' => round($amount[0], 3),
                    'unit' => $amount[1]->value,
                ]);
                $converted[] = $owner->name;
            }
        });

        return ['converted' => array_values(array_unique($converted)), 'skipped' => $skipped];
    }

    /** @return array{0: float, 1: IngredientUnit}|null cantidad y unidad para la sub-receta */
    private function amount(RecipeIngredient $ingredient, Product $product, Recipe $recipe): ?array
    {
        $unit = $ingredient->unit;
        if (! $ingredient->quantity || ! $unit) {
            return null;
        }

        $yield = $recipe->yieldDimension();
        if ($yield && $unit->dimension() === $yield) {
            return [$ingredient->quantity, $unit];
        }

        $base = $product->fromIngredient($unit, $ingredient->quantity);
        $dimension = $product->unit->dimension();
        if ($base !== null && $yield && $dimension === $yield) {
            return [$base, $yield === 'mass' ? IngredientUnit::G : IngredientUnit::Ml];
        }
        if ($base !== null && $yield && $dimension === 'count' && $product->piece_weight) {
            // Piezas → gramos con el peso de la pieza
            $grams = $base * $product->piece_weight;

            return $yield === 'mass' ? [$grams, IngredientUnit::G] : null;
        }
        if ($base !== null && $dimension === 'count') {
            return [$base, IngredientUnit::Serving];
        }

        return null;
    }
}
