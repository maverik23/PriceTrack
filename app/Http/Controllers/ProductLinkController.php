<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductLink;
use App\Models\Provider;
use App\Services\PriceUpdater;
use App\Support\Barcode;
use Illuminate\Http\Request;

/** El artículo en cada tienda (marca, código de barras, enlace) y consulta de sus precios en línea. */
class ProductLinkController extends Controller
{
    public function update(Request $request, Product $product)
    {
        $stores = collect($request->input('stores', []))->map(fn ($store) => [
            'url' => trim((string) ($store['url'] ?? '')) ?: null,
            'brand' => trim((string) ($store['brand'] ?? '')) ?: null,
            'barcode' => Barcode::normalize($store['barcode'] ?? null),
        ]);
        $request->merge(['stores' => $stores->all()]);
        $request->validate([
            'stores' => ['array'],
            'stores.*.url' => ['nullable', 'string', 'max:1000', 'url:https'],
            'stores.*.brand' => ['nullable', 'string', 'max:100'],
            'stores.*.barcode' => ['nullable', Barcode::RULE],
        ]);

        foreach (Provider::pluck('id') as $providerId) {
            $store = $stores->get($providerId);
            if (! $store || ! array_filter($store)) {
                $product->links()->where('provider_id', $providerId)->delete();

                continue;
            }

            $link = $product->links()->firstOrNew(['provider_id' => $providerId]);
            if ($link->url !== $store['url']) {
                // Otra página: lo que se leyó de la anterior ya no aplica
                $link->fill(['checked_at' => null, 'error' => null, 'store_name' => null, 'sku' => null]);
            }
            $link->fill([
                'url' => $store['url'],
                'brand' => Product::canonicalValue('brand', $store['brand']),
                'barcode' => $store['barcode'],
            ])->save();
        }

        return back()->with('success', 'Datos por tienda guardados.');
    }

    /** Consulta todas las tiendas con enlace y registra los precios con fecha de hoy. */
    public function fetch(Product $product, PriceUpdater $updater)
    {
        set_time_limit(180);
        $links = $product->links()->whereNotNull('url')->with(['provider', 'product'])->get();

        if ($links->isEmpty()) {
            return back()->with('success', 'Primero pega el enlace del artículo en alguna tienda.');
        }

        $summary = $links->map(fn (ProductLink $link) => $link->provider->name.': '.$updater->update($link)['message']);

        return back()->with('success', 'Precios en línea · '.$summary->join(' · '));
    }
}
