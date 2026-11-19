<?php

namespace App\Support;

use App\Enums\Unit;
use App\Models\Product;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;

/** Campos de un artículo (ProductController y el MCP update-product): reglas, normalización y el artículo como entrada. */
class ProductData
{
    public static function validate(array $input, ?Product $product = null): array
    {
        $input['barcode'] = Barcode::normalize($input['barcode'] ?? null);

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'brand' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', Barcode::RULE, Rule::unique('products', 'barcode')->ignore($product)],
            'category' => ['nullable', 'string', 'max:60'],
            'size' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'unit' => ['required', Rule::enum(Unit::class)],
            'notes' => ['nullable', 'string', 'max:255'],
            'piece_weight' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'cup_grams' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'kcal' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'protein' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'carbs' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'fat' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ], [], [
            'piece_weight' => 'peso de una pieza', 'cup_grams' => 'peso de una taza',
            'protein' => 'proteína', 'carbs' => 'carbohidratos', 'fat' => 'grasa',
        ]);

        // No permitir cambiar de dimensión (ej. ml → kg) si ya hay precios registrados
        $validator->after(function (ValidatorInstance $v) use ($input, $product) {
            if (! $product || $v->errors()->isNotEmpty()) {
                return;
            }
            $newUnit = Unit::from($input['unit']);
            if ($product->prices()->get()->contains(fn ($p) => ! $p->unit->isCompatibleWith($newUnit))) {
                $v->errors()->add('unit', 'Ya hay precios registrados en otra dimensión (volumen/peso/piezas). Usa una unidad compatible.');
            }
        });

        $data = $validator->validate();

        // Reusar la marca/categoría existente aunque se escriba distinto ("lacteos" → "Lácteos")
        $data['brand'] = Product::canonicalValue('brand', $data['brand'] ?? null);
        $data['category'] = Product::canonicalValue('category', $data['category'] ?? null);

        return $data;
    }

    public static function toInput(Product $product): array
    {
        return $product->only(['name', 'brand', 'barcode', 'category', 'size', 'notes', 'piece_weight', 'cup_grams', 'kcal', 'protein', 'carbs', 'fat'])
            + ['unit' => $product->unit->value];
    }
}
