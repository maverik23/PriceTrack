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

    protected static function booted(): void
    {
        // Comprar un básico de la despensa que se había acabado lo vuelve a marcar en casa.
        // Solo los que se llevan sin cantidad: no se lleva inventario automático.
        static::saved(function (ShoppingListItem $item) {
            if ($item->price_id && ($item->wasChanged('price_id') || $item->wasRecentlyCreated)) {
                $product = $item->product;
                if ($product?->pantry === Product::PANTRY_OUT) {
                    $product->setPantry(true);
                }
            }
        });
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

    /** Si ya estaba comprado en esa tienda, la compra pasa a su precio nuevo. */
    public function followPrice(Price $price): void
    {
        if ($this->price && $this->price->provider_id === $price->provider_id && $this->price_id !== $price->id) {
            $this->update(['price_id' => $price->id]);
        }
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
