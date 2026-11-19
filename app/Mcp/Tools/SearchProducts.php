<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Product;
use App\Support\Barcode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Busca artículos del súper por nombre, marca, categoría o código de barras. Devuelve cada artículo con su último precio en cada tienda, ordenado del más barato por unidad ($/L, $/kg, $/pza) al más caro. Sin argumentos lista todos (hasta el límite).')]
#[IsReadOnly]
class SearchProducts extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'query' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:60'],
            'barcode' => ['nullable', 'string', 'max:20'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if (filled($data['barcode'] ?? null)) {
            $code = Barcode::normalize($data['barcode']);
            $match = $code ? Product::findByBarcode($code) : null;

            return $this->json(['products' => $match ? [Present::product($match->load('prices.provider'))] : []]);
        }

        $query = Product::with('prices.provider')
            ->when(filled($data['query'] ?? null), function ($q) use ($data) {
                $term = '%'.trim($data['query']).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('brand', 'like', $term));
            })
            ->when(filled($data['category'] ?? null), fn ($q) => $q->where('category', Product::canonicalValue('category', $data['category'])))
            ->orderBy('name');

        $total = (clone $query)->count();
        $products = $query->take($data['limit'] ?? 25)->get();

        return $this->json([
            'total' => $total,
            'products' => $products->map(fn (Product $p) => Present::product($p))->all(),
            'categories' => Product::distinctValues('category')->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Texto a buscar en el nombre o la marca.'),
            'category' => $schema->string()->description('Solo artículos de esta categoría.'),
            'barcode' => $schema->string()->description('Código de barras (EAN/UPC); si se da, ignora lo demás.'),
            'limit' => $schema->integer()->description('Máximo de artículos (25 por defecto, hasta 100).'),
        ];
    }
}
