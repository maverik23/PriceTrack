<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Product;
use App\Models\ProductLink;
use App\Models\ShoppingList;
use App\Services\PriceUpdater;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Consulta en internet el precio de hoy de un artículo en las tiendas donde tiene enlace guardado (ver `stores` en get-product) y lo registra. Puede tardar hasta un par de minutos; algunas tiendas bloquean la consulta y lo indica en el resultado.')]
#[IsOpenWorld]
class FetchOnlinePrices extends Tool
{
    public function handle(Request $request, PriceUpdater $updater): Response
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'list_id' => ['nullable', 'integer', 'exists:shopping_lists,id'],
        ]);
        $product = Product::findOrFail($data['product_id']);
        $list = isset($data['list_id']) ? ShoppingList::findOrFail($data['list_id']) : null;
        $links = $product->links()->whereNotNull('url')->with(['product', 'provider'])->get();

        if ($links->isEmpty()) {
            return Response::error("«{$product->name}» no tiene enlaces a tiendas. Se agregan en su ficha (\"En cada tienda\"): ".route('products.show', $product));
        }

        set_time_limit(180);
        $item = $list?->items()->where('product_id', $product->id)->with('price')->first();
        $results = $links->map(function (ProductLink $link) use ($updater, $list, $item) {
            $result = $updater->update($link, $list);
            if ($result['price']) {
                $item?->followPrice($result['price']);
            }

            return ['provider' => $link->provider?->name, 'ok' => $result['ok'], 'message' => $result['message']];
        });

        return $this->json([
            'results' => $results->all(),
            'latest_prices' => Present::product($product->load('prices.provider'))['latest_prices'],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->description('Id del artículo.')->required(),
            'list_id' => $schema->integer()->description('Si se da, los precios quedan con la fecha de esa lista.'),
        ];
    }
}
