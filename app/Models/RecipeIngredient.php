<?php

namespace App\Models;

use App\Enums\IngredientUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ingrediente de una receta: un artículo del súper (`product_id`), otra receta
 * (`subrecipe_id`, en porciones o en g/ml si la sub-receta dice cuánto rinde) o
 * texto libre. Guarda el nombre para no perderlo si se elimina lo que liga.
 */
#[Fillable(['recipe_id', 'product_id', 'subrecipe_id', 'name', 'quantity', 'unit', 'note', 'section', 'position'])]
class RecipeIngredient extends Model
{
    protected function casts(): array
    {
        return [
            'unit' => IngredientUnit::class,
            'quantity' => 'float',
            'position' => 'integer',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function subrecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'subrecipe_id');
    }

    public function displayName(): string
    {
        return $this->product?->name ?? $this->subrecipe?->name ?? $this->name;
    }

    /**
     * Lo que se usa del artículo, en su unidad base (g, ml o pza), con las conversiones de
     * cocina del artículo (taza, pieza). Null sin artículo, sin cantidad o si ya no se
     * puede convertir (alguien cambió el artículo de kg a pza o borró el peso de la taza).
     */
    public function productBaseQty(float $factor = 1): ?float
    {
        if (! $this->product || ! $this->quantity || ! $this->unit) {
            return null;
        }

        return $this->product->fromIngredient($this->unit, $this->quantity * $factor);
    }

    /**
     * Por cuánto se multiplica la sub-receta: porciones usadas ÷ porciones que rinde, o
     * g/ml usados ÷ lo que rinde. Null si no es sub-receta o la unidad ya no se puede usar
     * (alguien le quitó el rendimiento).
     */
    public function subrecipeFactor(float $factor = 1): ?float
    {
        $sub = $this->subrecipe;
        if (! $sub || ! $this->quantity || ! $this->unit) {
            return null;
        }
        $amount = $this->quantity * $factor;

        return match (true) {
            $this->unit === IngredientUnit::Serving => $amount / max(1, $sub->servings),
            $this->unit->dimension() === $sub->yieldDimension() => $amount * $this->unit->toBase() / $sub->yieldBase(),
            default => null,
        };
    }

    /** ¿La sub-receta se está expandiendo ya (ciclo) o se pasó del límite de profundidad? */
    private function blocked(array $stack): bool
    {
        return in_array($this->subrecipe_id, $stack, true) || count($stack) >= Recipe::MAX_DEPTH;
    }

    /**
     * kcal, proteína, carbohidratos y grasa de lo que se usa; null si el artículo no
     * tiene esos datos o no se puede convertir.
     *
     * @return array<string, ?float>|null
     */
    public function nutrition(float $factor = 1): ?array
    {
        $base = $this->productBaseQty($factor);
        if ($base === null || ! $this->product->hasNutrition()) {
            return null;
        }
        // Por 100 g/ml, o por pieza en artículos que se miden en piezas
        $portions = $this->product->unit->dimension() === 'count' ? $base : $base / 100;

        return collect(Product::NUTRIENTS)->map(fn ($label, $key) => $this->product->{$key} === null ? null : $portions * $this->product->{$key})->all();
    }

    /** @return array{total: array<string, float>, missing: int, counted: int}|null */
    public function subrecipeNutrition(float $factor = 1, array $stack = []): ?array
    {
        $subFactor = $this->subrecipeFactor($factor);

        return $subFactor === null || $this->blocked($stack) ? null : $this->subrecipe->nutritionAt($subFactor, $stack);
    }

    /** Gramos aproximados de lo que se usa (para el tamaño de la porción). */
    public function grams(float $factor = 1, array $stack = []): ?float
    {
        if ($this->product) {
            $base = $this->productBaseQty($factor);

            return $base === null ? null : $this->product->gramsOf($base);
        }
        if ($this->subrecipe_id) {
            if ($this->unit?->dimension() === 'mass') {
                return $this->quantity * $this->unit->toBase() * $factor;
            }
            $subFactor = $this->subrecipeFactor($factor);

            return $subFactor === null || $this->blocked($stack) ? null : $this->subrecipe->gramsAt($subFactor, $stack);
        }
        if (! $this->quantity || ! $this->unit || in_array($this->unit->dimension(), ['count', 'serving'], true)) {
            return null;
        }

        return $this->quantity * $this->unit->toBase() * $factor;
    }

    /** "250 g", "1.5 L", "2 porciones"; vacío si no tiene cantidad. resources/js/recipe-show.js lo replica. */
    public function quantityLabel(float $factor = 1): string
    {
        if (! $this->quantity) {
            return '';
        }

        $quantity = round($this->quantity * $factor, 3);

        return trim(qty($quantity).' '.$this->unit?->labelFor($quantity));
    }

    /** Lo que cuesta lo que se usa, con el precio más barato por unidad del artículo (o lo que cuesta la sub-receta). */
    public function cost(float $factor = 1, array $stack = []): ?float
    {
        if ($this->subrecipe_id) {
            $subFactor = $this->subrecipeFactor($factor);

            return $subFactor === null || $this->blocked($stack) ? null : $this->subrecipe->costAt($subFactor, $stack);
        }

        $base = $this->productBaseQty($factor);
        $price = $base === null ? null : $this->product->cheapestPrice();

        return $base === null || ! $price ? null : round($base * $price->unit_price, 2);
    }
}
