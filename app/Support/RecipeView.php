<?php

namespace App\Support;

use App\Enums\IngredientUnit;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;

/**
 * Datos de la pantalla de una receta para Alpine (resources/js/recipe-show.js): los
 * valores de la receta tal como está escrita (factor 1). Cantidades, costos y macros son
 * lineales en el factor (porciones ÷ porciones que rinde): la pantalla solo multiplica.
 */
class RecipeView
{
    /** @return array<string, mixed> */
    public static function data(Recipe $recipe): array
    {
        $stack = [$recipe->id];
        $nutrition = $recipe->nutritionAt(1);

        return [
            'id' => $recipe->id,
            'name' => $recipe->name,
            'servings' => $recipe->servings,
            'units' => collect(IngredientUnit::cases())->mapWithKeys(fn (IngredientUnit $u) => [$u->value => ['label' => $u->label(), 'plural' => $u->labelFor(2)]])->all(),
            'cost' => $recipe->costAt(1, [], false),
            'uncosted' => $recipe->uncostedCount(),
            'nutrition' => $nutrition ? ['total' => $nutrition['total'], 'missing' => $nutrition['missing']] : null,
            'sections' => array_map(fn (array $group) => [
                'name' => $group['section'],
                'items' => array_map(fn (RecipeIngredient $i) => self::ingredient($i, $stack), $group['ingredients']),
            ], $recipe->ingredientSections()),
            'steps' => $recipe->steps->map(fn (RecipeStep $s) => [
                'stage' => $s->stage,
                'body' => $s->body,
                'minutes' => $s->minutes,
            ])->values()->all(),
            'urls' => [
                'rate' => route('recipes.rate', $recipe),
                'shop' => route('recipes.shop.review', $recipe),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function ingredient(RecipeIngredient $ingredient, array $stack): array
    {
        $cost = $ingredient->cost(1, $stack, false);
        $price = $ingredient->product?->cheapestPrice();

        return [
            'id' => $ingredient->id,
            'name' => $ingredient->displayName(),
            'kind' => match (true) {
                (bool) $ingredient->product => 'product',
                (bool) $ingredient->subrecipe => 'recipe',
                default => 'text',
            },
            'url' => match (true) {
                (bool) $ingredient->product => route('products.show', $ingredient->product),
                (bool) $ingredient->subrecipe => route('recipes.show', $ingredient->subrecipe),
                default => null,
            },
            'quantity' => $ingredient->quantity,
            'unit' => $ingredient->unit?->value,
            'note' => $ingredient->note,
            'cost' => $cost,
            // De dónde sale el precio ("Walmart · $30.00/kg")
            'source' => $cost !== null && $price ? $price->provider?->name.' · '.money($price->comparePrice()).$price->compareLabel() : null,
            'status' => match (true) {
                $cost !== null => 'ok',
                $ingredient->product !== null || $ingredient->subrecipe !== null => 'noprice',
                default => 'text',
            },
            'pantry' => $ingredient->product?->inPantry() ? ($ingredient->product->pantry_qty === null ? 'en casa' : 'quedan '.$ingredient->product->pantryLabel()) : null,
        ];
    }

    /** "250 g", "1.5 L", "2 porciones": lo mismo que RecipeIngredient::quantityLabel(). */
    public static function quantityLabel(?float $quantity, ?string $unit, float $factor = 1): string
    {
        return (new RecipeIngredient(['quantity' => $quantity, 'unit' => $unit]))->quantityLabel($factor);
    }
}
