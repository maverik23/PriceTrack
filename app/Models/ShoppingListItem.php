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
     * Estimado: lo que costaría en la tienda más barata para lo que se necesita,
     * con el último precio de cada una (paquetes completos o a granel).
     */
    public function estimatedSubtotal(): ?float
    {
        $need = $this->needBaseQty();

        return $this->product->latestPrices()->map(fn (Price $price) => $price->costFor($need))->min();
    }
}
