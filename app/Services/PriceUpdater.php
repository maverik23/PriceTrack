<?php

namespace App\Services;

use App\Enums\Unit;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductLink;
use App\Models\Setting;
use App\Models\ShoppingList;
use App\Support\Barcode;
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

        // Lo que la tienda dice de su versión del artículo: nombre, marca y códigos
        $barcode = Barcode::fromSearch($found['barcode']);
        $link->update(array_filter([
            'store_name' => $found['name'] ? Str::limit($found['name'], 200, '') : null,
            'brand' => $found['brand'] ? Product::canonicalValue('brand', Str::limit($found['brand'], 100, '')) : null,
            'barcode' => $barcode && Barcode::isGtin($barcode) ? $barcode : null,
            'sku' => $found['sku'] ? Str::limit($found['sku'], 40, '') : null,
        ]) + ['checked_at' => now(), 'error' => null]);

        return [
            'provider_id' => $link->provider_id,
            'ok' => true,
            'price' => $price,
            'message' => money($price->price).' · '.$price->presentationLabel(),
        ];
    }

    /**
     * Presentación del precio encontrado (piezas, contenido, unidad), en este orden:
     * 1. la que da la tienda en sus datos (por kg, "Contenido del empaque", "Contenido Neto");
     * 2. la del nombre del producto en la tienda ("… 8 pzas de 375 g", "… 1.8L");
     * 3. la que ya se usaba en esa tienda;
     * 4. la presentación habitual del artículo.
     * Solo se toma si es de la misma dimensión que el artículo (volumen, peso o piezas).
     */
    private function presentation(Product $product, int $providerId, array $found): array
    {
        $compatible = fn (?array $p) => $p && Unit::from($p['unit'])->isCompatibleWith($product->unit);

        foreach ([$found['presentation'], PriceFetcher::presentation($found['name'])] as $candidate) {
            if ($compatible($candidate)) {
                return $candidate;
            }
        }

        $last = $product->prices()->where('provider_id', $providerId)->orderByDesc('observed_on')->orderByDesc('id')->first();
        if ($last) {
            return ['pack_qty' => $last->pack_qty, 'size' => $last->size, 'unit' => $last->unit->value, 'bulk' => $last->bulk];
        }

        return ['pack_qty' => 1, 'size' => $product->size, 'unit' => $product->unit->value, 'bulk' => false];
    }
}
