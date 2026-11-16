<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El artículo en una tienda: su versión ahí (marca, código de barras, código y
 * nombre en la tienda) y, opcional, la página para consultar su precio en línea
 * (App\Services\PriceFetcher), que también llena esos datos.
 */
#[Fillable(['product_id', 'provider_id', 'url', 'store_name', 'brand', 'barcode', 'sku', 'checked_at', 'error'])]
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
