<?php

namespace App\Models;

use App\Enums\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['recipe_id', 'product_id', 'name', 'quantity', 'unit', 'note', 'position'])]
class RecipeIngredient extends Model
{
    protected function casts(): array
    {
        return [
            'unit' => Unit::class,
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

    /** Cantidad en unidad base (ml, g, pza); null si es texto libre sin cantidad. */
    public function baseQty(float $factor = 1): ?float
    {
        return $this->quantity && $this->unit ? $this->quantity * $this->unit->toBase() * $factor : null;
    }

    /**
     * Lo que se usa del artículo, en su unidad base. Null si no hay artículo o
     * si la unidad ya no es de su dimensión (alguien cambió el artículo de kg a pza).
     */
    public function productBaseQty(float $factor = 1): ?float
    {
        if (! $this->product || ! $this->unit?->isCompatibleWith($this->product->unit)) {
            return null;
        }

        return $this->baseQty($factor);
    }

    /** "250 g", "1.5 L"; vacío si no tiene cantidad. */
    public function quantityLabel(float $factor = 1): string
    {
        if (! $this->quantity) {
            return '';
        }

        return trim(qty(round($this->quantity * $factor, 3)).' '.$this->unit?->label());
    }

    /** Lo que cuesta lo que se usa, con el precio más barato por unidad del artículo. */
    public function cost(float $factor = 1): ?float
    {
        $base = $this->productBaseQty($factor);
        $price = $base === null ? null : $this->product->cheapestPrice();

        return $base === null || ! $price ? null : round($base * $price->unit_price, 2);
    }
}
