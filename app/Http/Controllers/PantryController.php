<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Despensa: lo que ya hay en casa, para no mandarlo a la lista de compras. */
class PantryController extends Controller
{
    public function index()
    {
        $products = Product::orderBy('name')->get();

        return view('pantry.index', [
            'rows' => $products->map(fn (Product $p) => self::row($p))->values(),
            'categories' => Product::distinctValues('category'),
            'counts' => [
                'stocked' => $products->where('pantry', Product::PANTRY_STOCKED)->count(),
                'out' => $products->where('pantry', Product::PANTRY_OUT)->count(),
            ],
        ]);
    }

    /** Marca o desmarca un artículo (have=1|0) y, opcional, cuánto queda (qty: "300 g", "1.5 kg"). */
    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'have' => ['required', 'boolean'],
            'qty' => ['nullable', 'string', 'max:30'],
            // Dejar de llevarlo en la despensa (ni "en casa" ni "se acabó")
            'forget' => ['nullable', 'boolean'],
        ]);

        $qty = $product->parseAmount($data['qty'] ?? null);
        if ($qty === false) {
            throw ValidationException::withMessages(['qty' => "No entendí la cantidad. Escríbela como «300 g» o «1.5 kg» (en {$product->unit->label()})."]);
        }

        if ($request->boolean('forget')) {
            $product->forceFill(['pantry' => null, 'pantry_qty' => null])->save();
        } else {
            $product->setPantry($request->boolean('have'), $qty);
        }

        if ($request->expectsJson()) {
            return response()->json(['row' => self::row($product->fresh())]);
        }

        return back()->with('success', match (true) {
            $product->inPantry() => "«{$product->name}» está en tu despensa.",
            $product->pantry === Product::PANTRY_OUT => "«{$product->name}» se acabó: irá a la lista cuando una receta lo pida.",
            default => "«{$product->name}» ya no se lleva en la despensa.",
        });
    }

    /** @return array<string, mixed> */
    public static function row(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'brand' => $product->brand,
            'category' => $product->category,
            'presentation' => $product->sizeLabel(),
            'unit' => $product->unit->label(),
            'pantry' => $product->pantry,
            'qty' => $product->pantryLabel(),
            'updated' => $product->pantry_updated_at?->diffForHumans(),
            'url' => route('products.show', $product),
            'action' => route('products.pantry', $product),
        ];
    }
}
