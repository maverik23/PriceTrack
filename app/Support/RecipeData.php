<?php

namespace App\Support;

use App\Enums\IngredientUnit;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Valida una receta con sus ingredientes: la usan el formulario y el servidor MCP. */
class RecipeData
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>} receta e ingredientes
     */
    public static function validate(array $input): array
    {
        // Los renglones vacíos del formulario no cuentan
        $rows = collect($input['ingredients'] ?? [])
            ->filter(fn ($row) => is_array($row) && (filled($row['product_id'] ?? null) || filled($row['name'] ?? null)))
            ->values()->all();
        $input['ingredients'] = $rows;

        $data = Validator::validate($input, [
            'name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:60'],
            'servings' => ['required', 'integer', 'min:1', 'max:999'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'source_url' => ['nullable', 'url', 'max:500'],
            'notes' => ['nullable', 'string', 'max:255'],
            'ingredients' => ['array', 'max:100'],
            'ingredients.*.product_id' => ['nullable', 'exists:products,id'],
            'ingredients.*.name' => ['nullable', 'string', 'max:150'],
            'ingredients.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'ingredients.*.unit' => ['nullable', Rule::enum(IngredientUnit::class)],
            'ingredients.*.note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'servings' => 'porciones',
            'minutes' => 'tiempo',
            'instructions' => 'preparación',
            'source_url' => 'dirección de origen',
            'ingredients.*.quantity' => 'cantidad',
            'ingredients.*.unit' => 'unidad',
        ]);

        $products = Product::findMany(collect($data['ingredients'] ?? [])->pluck('product_id')->filter())->keyBy('id');
        $ingredients = [];

        foreach ($data['ingredients'] ?? [] as $i => $row) {
            $product = $products->get($row['product_id'] ?? null);
            $unit = filled($row['unit'] ?? null) ? IngredientUnit::from($row['unit']) : null;
            $quantity = filled($row['quantity'] ?? null) ? (float) $row['quantity'] : null;

            if ($product) {
                // Con artículo la cantidad es obligatoria y de su misma dimensión: de ahí salen costo y lista
                if (! $quantity || ! $unit) {
                    throw ValidationException::withMessages(["ingredients.$i.quantity" => "Indica cantidad y unidad de «{$product->name}»."]);
                }
                if ($missing = $product->missingConversion($unit)) {
                    $allowed = implode(', ', array_map(fn (IngredientUnit $u) => $u->label(), array_filter(IngredientUnit::cases(), fn ($u) => $product->fromIngredient($u, 1) !== null)));
                    throw ValidationException::withMessages(["ingredients.$i.unit" => "«{$product->name}» se mide en {$allowed}. Para usar {$unit->label()} indica en el artículo {$missing}."]);
                }
            }

            $ingredients[] = [
                'product_id' => $product?->id,
                'name' => $product?->name ?? trim($row['name']),
                'quantity' => $quantity,
                'unit' => $quantity ? $unit?->value : null,
                'note' => $row['note'] ?? null,
                'position' => $i,
            ];
        }

        unset($data['ingredients']);
        $data['category'] = Recipe::canonicalCategory($data['category'] ?? null);

        return [$data, $ingredients];
    }
}
