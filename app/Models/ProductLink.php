<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Página de un artículo en una tienda, para consultar su precio en línea (App\Services\PriceFetcher). */
#[Fillable(['product_id', 'provider_id', 'url', 'checked_at', 'error'])]
class ProductLink extends Model
{
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
