<?php

namespace App\Mcp\Tools;

use App\Enums\Unit;
use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Product;
use App\Support\Barcode;
use App\Support\Categories;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Crea un artículo del súper con su presentación habitual (size + unit: "1 l", "680 g", "12 item"). Busca antes con search-products para no duplicarlo. La unidad define su dimensión (volumen, peso o piezas) y los precios deben usar esa misma dimensión.')]
class CreateProduct extends Tool
{
    public function handle(Request $request): Response
    {
        $request->merge(['barcode' => Barcode::normalize($request->get('barcode'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'size' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'unit' => ['required', Rule::enum(Unit::class)],
            'brand' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:60'],
            'barcode' => ['nullable', Barcode::RULE, 'unique:products,barcode'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['name' => 'nombre', 'size' => 'contenido', 'unit' => 'unidad', 'barcode' => 'código de barras']);

        $product = Product::create([
            ...$data,
            'name' => trim($data['name']),
            'brand' => Product::canonicalValue('brand', $data['brand'] ?? null),
            'category' => Product::canonicalValue('category', $data['category'] ?? null),
        ]);

        return $this->json(['created' => Present::product($product->load('prices.provider'))], Categories::notices($data, [
            'brand' => [$product->brand, 'product_brand'], 'category' => [$product->category, 'product_category'],
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Nombre del artículo, sin la presentación ("Leche entera").')->required(),
            'size' => $schema->number()->description('Contenido de la presentación habitual (1, 680, 12).')->required(),
            'unit' => $schema->string()->enum(array_column(Unit::cases(), 'value'))->description('ml, l, g, kg o item (piezas).')->required(),
            'brand' => $schema->string()->description('Marca.'),
            'category' => $schema->string()->description('Categoría (se reusa la escritura existente).'),
            'barcode' => $schema->string()->description('Código de barras EAN/UPC.'),
            'notes' => $schema->string(),
        ];
    }
}
