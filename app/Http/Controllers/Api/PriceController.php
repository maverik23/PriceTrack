<?php

namespace App\Http\Controllers\Api;

use App\Api\Present;
use App\Enums\Unit;
use App\Models\Price;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Historial de precios para la app móvil (capturar, corregir y borrar). */
class PriceController extends Controller
{
    public function index(Request $request)
    {
        $prices = Price::with(['product', 'provider', 'shoppingList'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';
                $query->where(fn ($q) => $q->where('notes', 'like', $term)
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $term)->orWhere('brand', 'like', $term)));
            })
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->when($request->filled('provider_id'), fn ($q) => $q->where('provider_id', $request->integer('provider_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('observed_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('observed_on', '<=', $request->date('to')))
            ->orderByDesc('observed_on')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->page($prices, fn (Price $p) => Present::price($p) + [
            'product' => $p->product?->fullName(),
            'list' => $p->shoppingList?->name,
        ]);
    }

    public function store(Request $request)
    {
        $price = Price::create($this->validated($request));

        return response()->json(['data' => Present::price($price->load('provider')), 'message' => "Precio de «{$price->product->name}» guardado."], 201);
    }

    public function update(Request $request, Price $price)
    {
        $price->update($this->validated($request, $price));

        return response()->json(['data' => Present::price($price->load('provider')), 'message' => 'Precio actualizado.']);
    }

    public function destroy(Price $price)
    {
        $price->delete();

        return response()->json(['message' => 'Precio eliminado.']);
    }

    /** Mismas reglas que el formulario de la web; al corregir solo cambia lo que se manda. */
    private function validated(Request $request, ?Price $price = null): array
    {
        $input = $request->all();
        if ($price) {
            $input += $price->only(['product_id', 'provider_id', 'price', 'pack_qty', 'size', 'bulk', 'notes'])
                + ['unit' => $price->unit->value, 'observed_on' => $price->observed_on->toDateString()];
        }

        $data = validator($input, [
            'product_id' => ['required', 'exists:products,id'],
            'provider_id' => ['required', 'exists:providers,id'],
            'price' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'pack_qty' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'size' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'unit' => ['required', Rule::enum(Unit::class)],
            'bulk' => ['nullable', 'boolean'],
            'observed_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['observed_on' => 'fecha', 'pack_qty' => 'piezas por paquete'])->validate();

        // La unidad debe ser de la misma dimensión que la del artículo (ml ↔ L, g ↔ kg, pza)
        $product = Product::findOrFail($data['product_id']);
        if (! Unit::from($data['unit'])->isCompatibleWith($product->unit)) {
            throw ValidationException::withMessages([
                'unit' => "«{$product->name}» se mide en {$product->unit->label()}; usa una unidad compatible.",
            ]);
        }

        $data['pack_qty'] = $data['pack_qty'] ?? 1;
        $data['bulk'] = (bool) ($data['bulk'] ?? false);

        return $data;
    }
}
