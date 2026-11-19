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

    /**
     * Direcciones guardadas por artículo y tienda, para traer el precio al capturarlo a mano
     * (resources/js/product-prices.js, priceLink).
     *
     * @return array<int, array<int, array{url: string, fetch: string, provider: ?string}>>
     */
    public static function fetchMap(?int $productId = null): array
    {
        $map = [];
        $links = static::with('provider')->whereNotNull('url')->when($productId, fn ($q) => $q->where('product_id', $productId))->get();
        foreach ($links as $link) {
            $map[$link->product_id][$link->provider_id] = [
                'url' => $link->url,
                'fetch' => route('product-links.fetch', $link),
                'provider' => $link->provider?->name,
            ];
        }

        return $map;
    }
}
