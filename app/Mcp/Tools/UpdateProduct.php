<?php

namespace App\Mcp\Tools;

use App\Enums\Unit;
use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Product;
use App\Support\ProductData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Corrige un artículo (`product_id`): solo lo que se manda (`null` borra un campo). Nombre, marca, categoría, código de barras, presentación habitual (size + unit; no se puede cambiar de dimensión si ya tiene precios en otra), notas, datos de cocina (`piece_weight` g por pieza, `cup_grams` g por taza) y nutrición por 100 g/ml o por pieza (kcal, protein, carbs, fat).')]
class UpdateProduct extends Tool
{
    public function handle(Request $request): Response
    {
        $request->validate(['product_id' => ['required', 'integer']]);
        $product = $this->find(Product::class, $request->get('product_id'), 'un artículo');
        $data = ProductData::validate(array_merge(ProductData::toInput($product), collect($request->all())->except('product_id')->all()), $product);
        $product->update($data);

        return $this->json(['product' => Present::product($product->fresh(['prices.provider']))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->description('El artículo (de search-products).')->required(),
            'name' => $schema->string(),
            'brand' => $schema->string(),
            'category' => $schema->string(),
            'barcode' => $schema->string(),
            'size' => $schema->number()->description('Presentación habitual: el contenido (1, 680, 12).'),
            'unit' => $schema->string()->enum(array_column(Unit::cases(), 'value'))->description('ml, l, g, kg o item.'),
            'notes' => $schema->string(),
            'piece_weight' => $schema->number()->description('Gramos de una pieza (para recetas).'),
            'cup_grams' => $schema->number()->description('Gramos de una taza (para recetas).'),
            'kcal' => $schema->number(),
            'protein' => $schema->number(),
            'carbs' => $schema->number(),
            'fat' => $schema->number(),
        ];
    }
}
