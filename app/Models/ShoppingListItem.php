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

    /** Lo que se pagó: cantidad × precio del paquete elegido. */
    public function subtotal(): ?float
    {
        return $this->price ? round($this->quantity * $this->price->price, 2) : null;
    }

    /**
     * Estimado: cantidad × presentación habitual, al precio por unidad más barato.
     * (Así un paquete de club 6 × 1 L no infla el estimado de "1 leche".)
     */
    public function estimatedSubtotal(): ?float
    {
        $cheapest = $this->product->cheapestPrice();

        return $cheapest ? round($this->quantity * $this->product->standardBaseQty() * $cheapest->unit_price, 2) : null;
    }
}
