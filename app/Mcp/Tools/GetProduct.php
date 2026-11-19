<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductLink;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Ficha de un artículo: presentación habitual, último precio en cada tienda (el primero es el más barato por unidad), historial reciente de precios, su versión en cada tienda (marca, enlace) y datos de cocina y nutrición.')]
#[IsReadOnly]
class GetProduct extends Tool
{
    public function handle(Request $request): Response
    {
        $product = $this->find(Product::class, $request->get('product_id'), 'un artículo', ['prices.provider', 'links.provider']);

        return $this->json([
            ...Present::product($product),
            'notes' => $product->notes,
            'history' => $product->prices
                ->sortByDesc(fn (Price $p) => $p->observed_on->format('Ymd').str_pad((string) $p->id, 10, '0', STR_PAD_LEFT))
                ->take(30)->map(fn (Price $p) => Present::price($p))->values()->all(),
            'stores' => $product->links->map(fn (ProductLink $link) => [
                'provider_id' => $link->provider_id,
                'provider' => $link->provider?->name,
                'brand' => $link->brand,
                'store_name' => $link->store_name,
                'url' => $link->url,
                'last_error' => $link->error,
            ])->all(),
            'kitchen' => ['piece_weight_g' => $product->piece_weight, 'cup_grams' => $product->cup_grams],
            'nutrition' => $product->hasNutrition() ? [
                'per' => $product->unit->dimension() === 'count' ? 'pieza' : '100 g/ml',
                'kcal' => $product->kcal, 'protein' => $product->protein, 'carbs' => $product->carbs, 'fat' => $product->fat,
            ] : null,
            'url' => route('products.show', $product),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['product_id' => $schema->integer()->description('Id del artículo (de search-products).')->required()];
    }
}
