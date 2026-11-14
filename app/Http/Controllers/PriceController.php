<?php

namespace App\Http\Controllers;

use App\Enums\Unit;
use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ShoppingListItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PriceController extends Controller
{
    public function index(Request $request)
    {
        $prices = Price::with(['product', 'provider', 'shoppingList'])
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->when($request->filled('provider_id'), fn ($q) => $q->where('provider_id', $request->integer('provider_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('observed_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('observed_on', '<=', $request->date('to')))
            ->orderByDesc('observed_on')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('prices.index', [
            'prices' => $prices,
            'products' => Product::orderBy('name')->get(),
            'providers' => Provider::orderBy('name')->get(),
        ]);
    }

    public function create(Request $request)
    {
        $product = $request->filled('product_id') ? Product::find($request->integer('product_id')) : null;

        return view('prices.create', [
            'price' => new Price([
                'product_id' => $product?->id,
                'provider_id' => $request->integer('provider_id') ?: null,
                'size' => $product?->size,
                'unit' => $product?->unit,
                'pack_qty' => 1,
                'observed_on' => today(),
            ]),
            'products' => Product::orderBy('name')->get(),
            'providers' => Provider::orderBy('name')->get(),
        ]);
    }

    /**
     * Guarda un precio. Si viene `item_id` (desde una lista) el precio queda
     * ligado a esa lista y, con `select=1`, se marca como el comprado.
     */
    public function store(Request $request)
    {
        $data = $this->validated($request);
        $item = $request->filled('item_id') ? ShoppingListItem::findOrFail($request->integer('item_id')) : null;

        if ($item) {
            $data['shopping_list_id'] = $item->shopping_list_id;
            $data['product_id'] = $item->product_id;
        }

        $price = Price::create($data);

        if ($item && $request->boolean('select')) {
            $item->update(['price_id' => $price->id]);
        }

        $name = $price->product->name;

        return $request->filled('redirect')
            ? redirect()->to($request->input('redirect'))->with('success', "Precio de «{$name}» guardado.")
            : back()->with('success', "Precio de «{$name}» guardado.");
    }

    public function edit(Price $price)
    {
        return view('prices.edit', [
            'price' => $price,
            'products' => Product::orderBy('name')->get(),
            'providers' => Provider::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Price $price)
    {
        $price->update($this->validated($request));

        return redirect()->route('products.show', $price->product_id)->with('success', 'Precio actualizado.');
    }

    public function destroy(Price $price)
    {
        $price->delete();

        return back()->with('success', 'Precio eliminado.');
    }

    private function validated(Request $request): array
    {
        $validator = validator($request->all(), [
            'product_id' => ['required_without:item_id', 'nullable', 'exists:products,id'],
            'item_id' => ['nullable', 'exists:shopping_list_items,id'],
            'provider_id' => ['required', 'exists:providers,id'],
            'price' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'pack_qty' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'size' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'unit' => ['required', Rule::enum(Unit::class)],
            'observed_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
            // Solo rutas internas ("/lists/3"), nunca "//otro-sitio.com"
            'redirect' => ['nullable', 'string', 'regex:#^/(?![/\\\\])#'],
        ]);

        // La unidad debe ser de la misma dimensión que la del artículo (ml ↔ L, g ↔ kg, pza)
        $validator->after(function (Validator $v) use ($request) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $productId = $request->filled('item_id')
                ? ShoppingListItem::find($request->integer('item_id'))?->product_id
                : $request->integer('product_id');
            $product = Product::find($productId);
            $unit = Unit::from($request->input('unit'));
            if ($product && ! $unit->isCompatibleWith($product->unit)) {
                $v->errors()->add('unit', "«{$product->name}» se mide en {$product->unit->label()}; usa una unidad compatible.");
            }
        });

        $data = $validator->validate();
        $data['pack_qty'] = $data['pack_qty'] ?? 1;
        $data['bulk'] = $request->boolean('bulk');
        unset($data['item_id'], $data['redirect']);

        return $data;
    }
}
