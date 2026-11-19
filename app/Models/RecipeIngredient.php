<?php

namespace App\Models;

use App\Enums\IngredientUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['recipe_id', 'product_id', 'name', 'quantity', 'unit', 'note', 'position'])]
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

    public function displayName(): string
    {
        return $this->product?->name ?? $this->name;
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
        $base = $this->product->fromIngredient($this->unit, $this->quantity * $factor);

        return $base === null ? null : $base;
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

    /** Gramos aproximados de lo que se usa (para el tamaño de la porción). */
    public function grams(float $factor = 1): ?float
    {
        if ($this->product) {
            $base = $this->productBaseQty($factor);

            return $base === null ? null : $this->product->gramsOf($base);
        }
        if (! $this->quantity || ! $this->unit || $this->unit->dimension() === 'count') {
            return null;
        }

        return $this->quantity * $this->unit->toBase() * $factor;
    }

    /** "250 g", "1.5 L"; vacío si no tiene cantidad. */
    public function quantityLabel(float $factor = 1): string
    {
        if (! $this->quantity) {
            return '';
        }

        $quantity = round($this->quantity * $factor, 3);

        return trim(qty($quantity).' '.$this->unit?->labelFor($quantity));
    }

    /** Lo que cuesta lo que se usa, con el precio más barato por unidad del artículo. */
    public function cost(float $factor = 1): ?float
    {
        $base = $this->productBaseQty($factor);
        $price = $base === null ? null : $this->product->cheapestPrice();

        return $base === null || ! $price ? null : round($base * $price->unit_price, 2);
    }
}
