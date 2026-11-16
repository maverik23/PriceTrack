<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductLink;
use App\Models\Provider;
use App\Services\PriceUpdater;
use Illuminate\Http\Request;

/** Enlaces de un artículo en cada tienda y consulta de sus precios en línea. */
class ProductLinkController extends Controller
{
    public function update(Request $request, Product $product)
    {
        $request->validate(['links' => ['array'], 'links.*' => ['nullable', 'string', 'max:1000', 'url:https']]);

        foreach (Provider::pluck('id') as $providerId) {
            $url = trim((string) $request->input("links.{$providerId}"));
            if ($url === '') {
                $product->links()->where('provider_id', $providerId)->delete();

                continue;
            }
            $link = $product->links()->firstOrNew(['provider_id' => $providerId]);
            if ($link->url !== $url) {
                $link->fill(['url' => $url, 'checked_at' => null, 'error' => null])->save();
            }
        }

        return back()->with('success', 'Enlaces guardados.');
    }

    /** Consulta todas las tiendas con enlace y registra los precios con fecha de hoy. */
    public function fetch(Product $product, PriceUpdater $updater)
    {
        set_time_limit(180);
        $links = $product->links()->with(['provider', 'product'])->get();

        if ($links->isEmpty()) {
            return back()->with('success', 'Primero pega el enlace del artículo en alguna tienda.');
        }

        $summary = $links->map(fn (ProductLink $link) => $link->provider->name.': '.$updater->update($link)['message']);

        return back()->with('success', 'Precios en línea · '.$summary->join(' · '));
    }
}
