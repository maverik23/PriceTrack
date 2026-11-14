<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shopping_list_id', 'product_id', 'quantity', 'price_id'])]
class ShoppingListItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
        ];
    }

    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }

    /** Lo que se necesita (o se compró) en unidad base: cantidad × presentación habitual. */
    public function needBaseQty(): float
    {
        return $this->quantity * $this->product->standardBaseQty();
    }

    /**
     * Lo que se pagó en la tienda elegida: lo justo si es a granel o los
     * paquetes completos que cubren la cantidad (ver Price::costFor()).
     */
    public function subtotal(): ?float
    {
        return $this->price?->costFor($this->needBaseQty());
    }

    /**
     * Estimado: cantidad × presentación habitual, al precio por unidad más barato.
     * (Así un paquete de club 6 × 1 L no infla el estimado de "1 leche".)
     */
    public function estimatedSubtotal(): ?float
    {
        $cheapest = $this->product->cheapestPrice();

        return $cheapest ? round($this->needBaseQty() * $cheapest->unit_price, 2) : null;
    }
}
