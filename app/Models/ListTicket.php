<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Total del ticket de una tienda en una lista: lo que de verdad se pagó ahí (con descuentos).
 * Si existe, manda sobre la suma de lo marcado como comprado en esa tienda, en la lista y en
 * los gastos (ReportService::spending() reparte la diferencia entre sus artículos).
 */
#[Fillable(['shopping_list_id', 'provider_id', 'total'])]
class ListTicket extends Model
{
    protected function casts(): array
    {
        return ['total' => 'float'];
    }

    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
