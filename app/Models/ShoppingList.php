<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['name', 'shopped_on', 'notes', 'completed_at'])]
class ShoppingList extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'shopped_on' => 'date',
            'completed_at' => 'datetime',
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

    /** Ya se compró: se tacha en el índice y deja de verse entre las pendientes. */
    public function isDone(): bool
    {
        return $this->completed_at !== null;
    }

    public function markDone(bool $done = true): void
    {
        $this->update(['completed_at' => $done ? ($this->completed_at ?? now()) : null]);
    }

    /** Al comprar el último pendiente la lista queda comprada sola. */
    public function completeIfAllBought(): void
    {
        if (! $this->completed_at && $this->items()->exists() && ! $this->items()->whereNull('price_id')->exists()) {
            $this->markDone();
        }
    }

    public function scopePending(Builder $query): void
    {
        $query->whereNull('completed_at');
    }

    public function scopeDone(Builder $query): void
    {
        $query->whereNotNull('completed_at');
    }
}
