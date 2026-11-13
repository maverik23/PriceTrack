<?php

namespace App\Http\Controllers;

use App\Enums\Unit;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ShoppingList;
use App\Services\BarcodeLookup;
use App\Support\Barcode;
use App\Support\ListGrid;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use RuntimeException;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        // Buscar (o escanear) un código de barras lleva directo al artículo
        $barcode = Barcode::fromSearch($request->input('q'));
        if ($barcode && $match = Product::firstWhere('barcode', $barcode)) {
            return redirect()->route('products.show', $match);
        }

        $products = Product::with('prices.provider')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('brand', 'like', $term));
            })
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->orderBy('name')
            ->get();

        return view('products.index', [
            'products' => $products,
            'categories' => Product::distinctValues('category'),
            'barcode' => $barcode,
        ]);
    }

    public function create(Request $request)
    {
        // ?barcode=… (desde el buscador) precarga el código y busca sus datos en internet
        $barcode = Barcode::fromSearch($request->query('barcode'));

        return view('products.create', [
            'product' => new Product(['size' => 1, 'unit' => Unit::Item, 'barcode' => $barcode]),
            'brands' => Product::distinctValues('brand'),
            'categories' => Product::distinctValues('category'),
            'addToList' => $request->integer('add_to_list') ?: null,
            'autoLookup' => $barcode !== null,
        ]);
    }

    /**
     * Busca un código de barras: primero entre los artículos guardados y, si no
     * está, en internet para autocompletar el formulario (JSON).
     */
    public function lookup(Request $request, BarcodeLookup $lookup)
    {
        $request->merge(['barcode' => Barcode::normalize($request->input('barcode'))]);
        $code = $request->validate(['barcode' => ['required', Barcode::RULE]])['barcode'];

        $product = Product::firstWhere('barcode', $code);
        $suggestion = null;
        $error = null;

        if (! $product) {
            try {
                $suggestion = $lookup->find($code);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        if ($suggestion) {
            // Reusar la escritura de las marcas y categorías que ya existen ("LALA" → "Lala")
            $suggestion['brand'] = Product::canonicalValue('brand', $suggestion['brand']);
            $suggestion['category'] = Product::canonicalValue('category', $suggestion['category']);
        }

        return response()->json([
            'barcode' => $code,
            'product' => $product ? ListGrid::product($product) : null,
            'suggestion' => $suggestion,
            'error' => $error,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $product = Product::create($data);

        if ($listId = $request->integer('add_to_list')) {
            $list = ShoppingList::findOrFail($listId);
            $list->items()->create(['product_id' => $product->id, 'quantity' => 1]);

            return redirect()->route('lists.show', $list)->with('success', "«{$product->name}» creado y agregado a la lista.");
        }

        return redirect()->route('products.show', $product)->with('success', 'Artículo creado. Ahora agrega sus precios.');
    }

    public function show(Product $product)
    {
        $product->load(['prices' => fn ($q) => $q->orderByDesc('observed_on')->orderByDesc('id'), 'prices.provider']);

        $latest = $product->latestPrices();
        $cheapest = $latest->first();
        $mostExpensive = $latest->last();

        // Serie por proveedor para la gráfica ($/L, $/kg o $/pza en el tiempo)
        $series = $product->prices
            ->sortBy(fn ($p) => $p->observed_on->format('Ymd').str_pad((string) $p->id, 10, '0', STR_PAD_LEFT))
            ->groupBy('provider_id')
            ->map(fn ($prices) => [
                'label' => $prices->first()->provider->name,
                'data' => $prices->map(fn ($p) => ['x' => $p->observed_on->format('Y-m-d'), 'y' => round($p->comparePrice(), 2)])->values(),
            ])->values();

        return view('products.show', [
            'product' => $product,
            'latest' => $latest,
            'cheapest' => $cheapest,
            'savings' => ($cheapest && $mostExpensive && $mostExpensive->unit_price > 0)
                ? 1 - $cheapest->unit_price / $mostExpensive->unit_price
                : null,
            'series' => $series,
            'providers' => Provider::orderBy('name')->get(),
        ]);
    }

    public function edit(Product $product)
    {
        return view('products.edit', [
            'product' => $product,
            'brands' => Product::distinctValues('brand'),
            'categories' => Product::distinctValues('category'),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $product->update($this->validated($request, $product));

        return redirect()->route('products.show', $product)->with('success', 'Artículo actualizado.');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()->route('products.index')->with('success', 'Artículo eliminado (junto con su historial de precios).');
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $request->merge(['barcode' => Barcode::normalize($request->input('barcode'))]);

        $validator = validator($request->all(), [
            'name' => ['required', 'string', 'max:150'],
            'brand' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', Barcode::RULE, Rule::unique('products', 'barcode')->ignore($product)],
            'category' => ['nullable', 'string', 'max:60'],
            'size' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'unit' => ['required', Rule::enum(Unit::class)],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        // No permitir cambiar de dimensión (ej. ml → kg) si ya hay precios registrados
        $validator->after(function (Validator $v) use ($request, $product) {
            if (! $product || $v->errors()->isNotEmpty()) {
                return;
            }
            $newUnit = Unit::from($request->input('unit'));
            $incompatible = $product->prices()->get()->contains(fn ($p) => ! $p->unit->isCompatibleWith($newUnit));
            if ($incompatible) {
                $v->errors()->add('unit', 'Ya hay precios registrados en otra dimensión (volumen/peso/piezas). Usa una unidad compatible.');
            }
        });

        $data = $validator->validate();

        // Reusar la marca/categoría existente aunque se escriba distinto ("lacteos" → "Lácteos")
        $data['brand'] = Product::canonicalValue('brand', $data['brand'] ?? null);
        $data['category'] = Product::canonicalValue('category', $data['category'] ?? null);

        return $data;
    }
}
