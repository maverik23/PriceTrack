<?php

namespace App\Services;

use App\Enums\Unit;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductLink;
use App\Models\Setting;
use App\Models\ShoppingList;
use Illuminate\Support\Str;

/**
 * Consulta el enlace de un artículo en una tienda y registra el precio encontrado
 * (con la fecha de la lista si se pide desde una lista; si no, con la de hoy).
 */
class PriceUpdater
{
    public function __construct(private PriceFetcher $fetcher) {}

    /**
     * @return array{provider_id: int, ok: bool, price: ?Price, message: string}
     */
    public function update(ProductLink $link, ?ShoppingList $list = null): array
    {
        try {
            $found = $this->fetcher->fetch($link->url, Setting::value(Setting::POSTAL_CODE));
        } catch (PriceFetchException $e) {
            $link->update(['checked_at' => now(), 'error' => Str::limit($e->getMessage(), 250)]);

            return ['provider_id' => $link->provider_id, 'ok' => false, 'price' => null, 'message' => $e->getMessage()];
        }

        $price = Price::record($link->product, $link->provider_id, $list, ['price' => $found['price']] + $this->presentation($link->product, $link->provider_id, $found));
        $link->update(['checked_at' => now(), 'error' => null]);

        return [
            'provider_id' => $link->provider_id,
            'ok' => true,
            'price' => $price,
            'message' => money($price->price).' · '.$price->presentationLabel(),
        ];
    }

    /**
     * Presentación del precio encontrado: la que dice la tienda (por kg); si no, la
     * que ya se usaba en esa tienda; si no, la del nombre en la tienda ("… 1.8L");
     * si no, la presentación habitual del artículo.
     */
    private function presentation(Product $product, int $providerId, array $found): array
    {
        $compatible = fn (string $unit) => Unit::from($unit)->isCompatibleWith($product->unit);

        if ($found['presentation'] && $compatible($found['presentation']['unit'])) {
            return $found['presentation'];
        }

        $last = $product->prices()->where('provider_id', $providerId)->orderByDesc('observed_on')->orderByDesc('id')->first();
        if ($last) {
            return ['pack_qty' => $last->pack_qty, 'size' => $last->size, 'unit' => $last->unit->value, 'bulk' => $last->bulk];
        }

        $parsed = $found['name'] ? BarcodeLookup::parseQuantity($found['name']) : null;
        if ($parsed && $compatible($parsed['unit'])) {
            return ['pack_qty' => 1, 'size' => $parsed['size'], 'unit' => $parsed['unit'], 'bulk' => false];
        }

        return ['pack_qty' => 1, 'size' => $product->size, 'unit' => $product->unit->value, 'bulk' => false];
    }
}
