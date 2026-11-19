<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shopping_list_id', 'product_id', 'quantity', 'price_id', 'bought_qty'])]
class ShoppingListItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'bought_qty' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // Lo comprado (paquetes) es de la tienda elegida: al cambiar de tienda o desmarcar se
        // vuelve a calcular. Un precio nuevo en la misma tienda lo conserva.
        static::saving(function (ShoppingListItem $item) {
            if ($item->exists && $item->isDirty('price_id') && ! $item->isDirty('bought_qty') && $item->bought_qty !== null) {
                $before = Price::find($item->getOriginal('price_id'));
                $after = $item->price_id ? Price::find($item->price_id) : null;
                if (! $before || ! $after || $before->provider_id !== $after->provider_id) {
                    $item->bought_qty = null;
                }
            }
        });

        // Comprar un básico de la despensa que se había acabado lo vuelve a marcar en casa.
        // Solo los que se llevan sin cantidad: no se lleva inventario automático.
        static::saved(function (ShoppingListItem $item) {
            if ($item->price_id && ($item->wasChanged('price_id') || $item->wasRecentlyCreated)) {
                $product = $item->product;
                if ($product?->pantry === Product::PANTRY_OUT) {
                    $product->setPantry(true);
                }
                $item->shoppingList?->completeIfAllBought();
            } elseif (! $item->price_id && ($item->wasChanged('price_id') || $item->wasRecentlyCreated)) {
                // Algo volvió a quedar pendiente (se desmarcó o se agregó): la lista ya no está comprada
                $list = $item->shoppingList;
                if ($list?->isDone()) {
                    $list->markDone(false);
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
     * Lo que se compró, en unidades del precio elegido: lo capturado (`bought_qty`) o, si no,
     * lo justo a granel o los paquetes completos que cubren lo pedido (Price::packsFor()).
     */
    public function boughtUnits(): ?float
    {
        if (! $this->price) {
            return null;
        }
        if ($this->bought_qty !== null) {
            return $this->bought_qty;
        }

        return $this->price->bulk
            ? $this->needBaseQty() / $this->price->base_qty
            : (float) $this->price->packsFor($this->needBaseQty());
    }

    /** Lo comprado en unidad base (ml, g, pza). */
    public function boughtBaseQty(): ?float
    {
        $units = $this->boughtUnits();

        return $units === null ? null : $units * $this->price->base_qty;
    }

    /**
     * Lo que se pagó en la tienda elegida: lo comprado × el precio. Sin capturar cuánto,
     * es lo justo a granel o los paquetes completos que cubren lo pedido (Price::costFor()).
     * El ticket de la tienda (ListTicket), si se capturó, manda sobre la suma.
     */
    public function subtotal(): ?float
    {
        $units = $this->boughtUnits();

        return $units === null ? null : round($units * $this->price->price, 2);
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
