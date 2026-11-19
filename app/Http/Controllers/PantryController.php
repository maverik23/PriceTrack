<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Despensa: lo que ya hay en casa, para no mandarlo a la lista de compras. */
class PantryController extends Controller
{
    public function index()
    {
        $products = Product::with('prices')->orderBy('name')->get();
        $pending = self::pendingItems();

        return view('pantry.index', [
            'rows' => $products->map(fn (Product $p) => self::row($p, $pending))->values(),
            'lists' => self::lists(),
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

    /**
     * Agrega a una lista de compras algo que se acabó (`list_id`; sin ella, a una lista nueva).
     * Si ya está pendiente en esa lista no se duplica.
     */
    public function addToList(Request $request, Product $product)
    {
        $data = $request->validate(['list_id' => ['nullable', 'integer', 'exists:shopping_lists,id']], [], ['list_id' => 'lista']);

        $list = isset($data['list_id']) ? ShoppingList::findOrFail($data['list_id']) : ShoppingList::forPantry();
        $already = ! $list->addProductOnce($product);
        $message = $already ? "«{$product->name}» ya estaba en «{$list->name}»." : "«{$product->name}» se agregó a «{$list->name}».";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'list' => ['id' => $list->id, 'name' => $list->name, 'url' => route('lists.show', $list)],
                'row' => self::row($product->fresh('prices')),
            ]);
        }

        return back()->with('success', $message);
    }

    /** Listas pendientes donde se puede agregar lo que se acabó. @return array<int, array{id: int, name: string}> */
    public static function lists(): array
    {
        return ShoppingList::pending()->orderByDesc('shopped_on')->orderByDesc('id')->take(10)->get(['id', 'name'])
            ->map(fn (ShoppingList $l) => ['id' => $l->id, 'name' => $l->name])->all();
    }

    /** Lista pendiente donde ya está cada artículo sin comprar: product_id => nombre de la lista. */
    public static function pendingItems(?int $productId = null): array
    {
        return ShoppingListItem::query()
            ->join('shopping_lists', 'shopping_lists.id', '=', 'shopping_list_items.shopping_list_id')
            ->whereNull('shopping_lists.completed_at')
            ->whereNull('shopping_list_items.price_id')
            ->when($productId, fn ($q) => $q->where('shopping_list_items.product_id', $productId))
            ->orderBy('shopping_lists.shopped_on')
            ->pluck('shopping_lists.name', 'shopping_list_items.product_id')
            ->all();
    }

    /**
     * @param  array<int, string>|null  $pending  product_id => lista pendiente donde ya está (null = se consulta)
     * @return array<string, mixed>
     */
    public static function row(Product $product, ?array $pending = null): array
    {
        $pending ??= self::pendingItems($product->id);
        $value = $product->pantryValue();

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
            'to_list' => route('products.pantry.list', $product),
            // Lo que vale lo que queda (estimado con una presentación si es "suficiente")
            'value' => $value['value'] ?? null,
            'estimated' => $value['estimated'] ?? false,
            // Lista pendiente donde ya está, para no preguntar de nuevo
            'in_list' => $pending[$product->id] ?? null,
        ];
    }
}
