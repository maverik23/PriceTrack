<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['name', 'shopped_on', 'notes', 'completed_at', 'provider_columns'])]
class ShoppingList extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'shopped_on' => 'date',
            'completed_at' => 'datetime',
            'provider_columns' => 'array',
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

    public function tickets(): HasMany
    {
        return $this->hasMany(ListTicket::class);
    }

    /**
     * Lo pagado en cada tienda: la suma de lo marcado ahí (`computed`, con cuántos artículos)
     * y el total del ticket si se capturó (`ticket`); `paid` = el ticket o, si no, la suma.
     * Necesita `items.price` cargado.
     *
     * @return array<int, array{computed: float, items: int, ticket: ?float, paid: float}>
     */
    public function paidByProvider(): array
    {
        $byProvider = [];
        foreach ($this->items as $item) {
            if ($item->price) {
                $row = &$byProvider[$item->price->provider_id];
                $row['computed'] = ($row['computed'] ?? 0) + ($item->subtotal() ?? 0);
                $row['items'] = ($row['items'] ?? 0) + 1;
                unset($row);
            }
        }
        foreach ($this->tickets as $ticket) {
            $byProvider[$ticket->provider_id]['ticket'] = $ticket->total;
        }

        return array_map(fn (array $r) => [
            'computed' => round($r['computed'] ?? 0, 2),
            'items' => $r['items'] ?? 0,
            'ticket' => $r['ticket'] ?? null,
            'paid' => round($r['ticket'] ?? $r['computed'] ?? 0, 2),
        ], $byProvider);
    }

    /** Total pagado: el ticket de cada tienda o, sin ticket, la suma de lo marcado ahí. */
    public function total(): float
    {
        return (float) array_sum(array_column($this->paidByProvider(), 'paid'));
    }

    /** Lo comprado + lo pendiente estimado con el más barato. */
    public function estimatedTotal(): float
    {
        return $this->total() + (float) $this->items->whereNull('price_id')->sum(fn (ShoppingListItem $item) => $item->estimatedSubtotal() ?? 0);
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
