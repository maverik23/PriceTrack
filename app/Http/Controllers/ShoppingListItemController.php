<?php

namespace App\Http\Controllers;

use App\Enums\Unit;
use App\Models\Price;
use App\Models\Product;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Support\ListGrid;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShoppingListItemController extends Controller
{
    /**
     * Agrega un artículo existente (product_id) o crea uno nuevo (name + size + unit).
     */
    public function store(Request $request, ShoppingList $list)
    {
        $data = $request->validate([
            'product_id' => ['required_without:name', 'nullable', 'exists:products,id'],
            'name' => ['required_without:product_id', 'nullable', 'string', 'max:150'],
            'size' => ['required_with:name', 'nullable', 'numeric', 'gt:0', 'max:9999999'],
            'unit' => ['required_with:name', 'nullable', Rule::enum(Unit::class)],
            'quantity' => ['nullable', 'numeric', 'gt:0', 'max:9999'],
        ]);

        $product = empty($data['product_id'])
            ? Product::create(['name' => trim($data['name']), 'size' => $data['size'], 'unit' => $data['unit']])
            : Product::findOrFail($data['product_id']);

        $item = $list->items()->firstOrNew(['product_id' => $product->id]);
        $existing = $item->exists;

        if ($request->expectsJson()) {
            // En la tabla, elegir un artículo que ya está solo lleva a su fila
            if (! $existing) {
                $item->quantity = $data['quantity'] ?? 1;
                $item->save();
            }
            $item->load(['product.prices', 'price']);

            return response()->json([
                'existing' => $existing,
                'row' => ListGrid::row($item, $list),
                'product' => ListGrid::product($product),
            ], $existing ? 200 : 201);
        }

        $item->quantity = ($existing ? $item->quantity : 0) + ($data['quantity'] ?? 1);
        $item->save();

        return back()->with('success', 'Artículo agregado a la lista.');
    }

    public function update(Request $request, ShoppingList $list, ShoppingListItem $item)
    {
        $data = $request->validate([
            'quantity' => ['sometimes', 'numeric', 'gt:0', 'max:9999'],
            // Solo se puede elegir un precio del mismo artículo
            'price_id' => ['sometimes', 'nullable', Rule::exists('prices', 'id')->where('product_id', $item->product_id)],
        ]);

        $item->update($data);

        if ($request->expectsJson()) {
            return response()->json(['item' => ListGrid::item($item, $list)]);
        }

        return back()->with('success', array_key_exists('price_id', $data)
            ? ($data['price_id'] ? 'Marcado como comprado.' : 'Compra desmarcada.')
            : 'Cantidad actualizada.');
    }

    public function destroy(Request $request, ShoppingList $list, ShoppingListItem $item)
    {
        $item->delete();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Artículo quitado de la lista.');
    }

    /**
     * Guarda el precio de una celda (artículo × proveedor) con la fecha de la lista.
     * Si ya se capturó ese día desde esta lista se corrige ese registro; si no,
     * se crea uno nuevo y el historial anterior se conserva.
     */
    public function savePrice(Request $request, ShoppingList $list, ShoppingListItem $item)
    {
        $data = $request->validate([
            'provider_id' => ['required', 'exists:providers,id'],
            'price' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'pack_qty' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'size' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'unit' => ['required', Rule::enum(Unit::class)],
        ]);

        $product = $item->product;
        $unit = Unit::from($data['unit']);
        if (! $unit->isCompatibleWith($product->unit)) {
            throw ValidationException::withMessages([
                'unit' => "«{$product->name}» se mide en {$product->unit->label()}; usa una unidad compatible.",
            ]);
        }

        $price = Price::firstOrNew([
            'product_id' => $product->id,
            'provider_id' => $data['provider_id'],
            'shopping_list_id' => $list->id,
            'observed_on' => $list->priceDate()->toDateString(),
        ]);
        $price->fill([
            'price' => $data['price'],
            'pack_qty' => $data['pack_qty'] ?? 1,
            'size' => $data['size'],
            'unit' => $unit,
        ])->save();

        // Si ya estaba comprado en ese proveedor, la compra pasa al precio nuevo
        if ($item->price && $item->price->provider_id === $price->provider_id) {
            $item->update(['price_id' => $price->id]);
        }

        return response()->json([
            'price' => ListGrid::price($price, $list),
            'item' => ListGrid::item($item, $list),
        ]);
    }

    /**
     * Borra un precio capturado desde esta lista (para corregir un error) y
     * devuelve el último precio que queda para ese proveedor.
     */
    public function destroyPrice(ShoppingList $list, ShoppingListItem $item, Price $price)
    {
        abort_unless($item->shopping_list_id === $list->id, 404);
        abort_unless($price->product_id === $item->product_id && $price->shopping_list_id === $list->id, 403,
            'Solo se pueden borrar precios capturados en esta lista.');

        $price->delete();

        $previous = Price::where('product_id', $item->product_id)
            ->where('provider_id', $price->provider_id)
            ->orderByDesc('observed_on')->orderByDesc('id')
            ->first();

        return response()->json([
            'price' => ListGrid::price($previous, $list),
            'item' => ListGrid::item($item->fresh(), $list),
        ]);
    }
}
