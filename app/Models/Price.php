<?php

namespace App\Models;

use App\Enums\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'provider_id', 'shopping_list_id', 'price', 'pack_qty', 'size', 'unit', 'observed_on', 'notes'])]
class Price extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'unit' => Unit::class,
            'observed_on' => 'date',
            'price' => 'float',
            'pack_qty' => 'integer',
            'size' => 'float',
            'base_qty' => 'float',
            'unit_price' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // Normaliza siempre a unidad base (ml, g, pza) para poder comparar.
        static::saving(function (Price $price) {
            $price->pack_qty = max(1, (int) $price->pack_qty);
            $price->base_qty = round($price->pack_qty * $price->size * $price->unit->toBase(), 3);
            $price->unit_price = $price->base_qty > 0 ? round($price->price / $price->base_qty, 6) : 0;
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    /** Precio por unidad comparativa: $/L, $/kg o $/pza. */
    public function comparePrice(): float
    {
        return $this->unit_price * $this->unit->compareFactor();
    }

    public function compareLabel(): string
    {
        return '/'.$this->unit->compareLabel();
    }

    public function presentationLabel(): string
    {
        $content = qty($this->size).' '.$this->unit->label();

        return $this->pack_qty > 1 ? $this->pack_qty.' × '.$content : $content;
    }
}
