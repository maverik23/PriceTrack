<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['name', 'shopped_on', 'notes'])]
class ShoppingList extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'shopped_on' => 'date',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    /** Fecha con la que se registran los precios capturados en esta lista (nunca futura). */
    public function priceDate(): Carbon
    {
        return $this->shopped_on->isFuture() ? today() : $this->shopped_on->copy();
    }

    /** Total de lo comprado (artículos con precio elegido). */
    public function total(): float
    {
        return (float) $this->items->sum(fn (ShoppingListItem $item) => $item->subtotal() ?? 0);
    }

    /** Lo comprado + lo pendiente estimado con el más barato. */
    public function estimatedTotal(): float
    {
        return (float) $this->items->sum(fn (ShoppingListItem $item) => $item->subtotal() ?? $item->estimatedSubtotal() ?? 0);
    }

    public function purchasedCount(): int
    {
        return $this->items->whereNotNull('price_id')->count();
    }
}
