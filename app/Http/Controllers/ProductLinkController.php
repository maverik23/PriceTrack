<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductLink;
use App\Models\Provider;
use App\Models\ShoppingList;
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

    /**
     * Consulta un enlace (JSON): registra el precio de hoy (o con la fecha de `list_id`) o,
     * con `preview=1`, solo lo devuelve para proponerlo en un formulario. La pantalla
     * consulta enlace por enlace para llevar el avance (resources/js/progress.js).
     */
    public function fetchLink(Request $request, ProductLink $link, PriceUpdater $updater)
    {
        $data = $request->validate([
            'list_id' => ['nullable', 'integer', 'exists:shopping_lists,id'],
            'preview' => ['nullable', 'boolean'],
        ]);
        abort_unless($link->url, 422, 'Ese artículo no tiene dirección en esa tienda.');
        set_time_limit(120);
        $link->load(['product', 'provider']);

        if ($request->boolean('preview')) {
            return response()->json(['provider_id' => $link->provider_id, 'provider' => $link->provider->name] + $updater->preview($link));
        }

        $list = isset($data['list_id']) ? ShoppingList::findOrFail($data['list_id']) : null;
        $result = $updater->update($link, $list);
        if ($list && $result['price']) {
            $list->items()->where('product_id', $link->product_id)->with('price')->first()?->followPrice($result['price']);
        }

        return response()->json([
            'ok' => $result['ok'],
            'message' => $result['message'],
            'provider_id' => $link->provider_id,
            'provider' => $link->provider->name,
            'product' => $link->product->name,
            'price' => $result['price']?->only(['id', 'price', 'unit_price']),
        ]);
    }
}
