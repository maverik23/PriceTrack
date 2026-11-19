<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Product;
use App\Models\Provider;
use App\Support\Barcode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Guarda la versión de un artículo en una tienda: la dirección de su página (`url`, https; con ella fetch-online-prices trae el precio de hoy), la marca que vende ahí y su código de barras. Solo cambia lo que se manda; si quedan los tres vacíos se quita.')]
class SaveProductLink extends Tool
{
    public function handle(Request $request): Response
    {
        foreach (['url', 'brand'] as $key) {
            if ($request->has($key)) {
                $request->merge([$key => trim((string) $request->get($key)) ?: null]);
            }
        }
        if ($request->has('barcode')) {
            $request->merge(['barcode' => Barcode::normalize($request->get('barcode'))]);
        }
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'provider_id' => ['required', 'integer', 'exists:providers,id'],
            'url' => ['nullable', 'string', 'max:1000', 'url:https'],
            'brand' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', Barcode::RULE],
        ], [], ['provider_id' => 'tienda', 'url' => 'dirección', 'brand' => 'marca', 'barcode' => 'código de barras']);

        $product = $this->find(Product::class, $data['product_id'], 'un artículo');
        $store = Provider::find($data['provider_id'])->name;
        $link = $product->links()->firstOrNew(['provider_id' => $data['provider_id']]);
        // Solo cambia lo que se manda
        $data += $link->only(['url', 'brand', 'barcode']);
        if (! filled($data['url']) && ! filled($data['brand']) && ! filled($data['barcode'])) {
            $product->links()->where('provider_id', $data['provider_id'])->delete();

            return $this->json(['removed' => "{$product->name} en {$store}"]);
        }

        if ($link->url !== $data['url']) {
            // Otra página: lo que se leyó de la anterior ya no aplica
            $link->fill(['checked_at' => null, 'error' => null, 'store_name' => null, 'sku' => null]);
        }
        $link->fill([
            'url' => $data['url'],
            'brand' => Product::canonicalValue('brand', $data['brand']),
            'barcode' => $data['barcode'],
        ])->save();

        return $this->json(['saved' => "{$product->name} en {$store}", 'link' => $link->only(['id', 'url', 'brand', 'barcode'])]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->required(),
            'provider_id' => $schema->integer()->description('La tienda (de list-providers).')->required(),
            'url' => $schema->string()->description('Página del artículo en esa tienda (https).'),
            'brand' => $schema->string()->description('Marca que vende esa tienda.'),
            'barcode' => $schema->string(),
        ];
    }
}
