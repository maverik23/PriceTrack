<?php

namespace App\Http\Controllers;

use App\Enums\Unit;
use App\Models\Product;
use App\Models\ProductLink;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\Setting;
use App\Models\ShoppingList;
use App\Services\BarcodeLookup;
use App\Services\PriceFetchException;
use App\Services\ProductPhoto;
use App\Services\SubrecipeConverter;
use App\Support\AttachmentForm;
use App\Support\Barcode;
use App\Support\ListGrid;
use App\Support\ProductData;
use Illuminate\Http\Request;
use RuntimeException;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        // Buscar (o escanear) un código de barras lleva directo al artículo
        $barcode = Barcode::fromSearch($request->input('q'));
        if ($barcode && $match = Product::findByBarcode($barcode)) {
            return redirect()->route('products.show', $match);
        }

        $products = Product::with(['prices.provider', 'attachments'])
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

        $product = Product::findByBarcode($code);
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

    public function store(Request $request, ProductPhoto $photos)
    {
        $data = $this->validated($request);
        AttachmentForm::validate($request);
        $product = Product::create($data);
        AttachmentForm::apply($product, $request);
        $this->photoFromInternet($request, $product, $photos);

        if ($listId = $request->integer('add_to_list')) {
            $list = ShoppingList::findOrFail($listId);
            $list->items()->create(['product_id' => $product->id, 'quantity' => 1]);

            return redirect()->route('lists.show', $list)->with('success', "«{$product->name}» creado y agregado a la lista.");
        }

        return redirect()->route('products.show', $product)->with('success', 'Artículo creado. Ahora agrega sus precios.');
    }

    /** Cambia este artículo (algo casero) por su receta en todas las recetas que lo usan. */
    public function useRecipe(Request $request, Product $product, SubrecipeConverter $converter)
    {
        $data = $request->validate(['recipe_id' => ['required', 'exists:recipes,id']], [], ['recipe_id' => 'receta']);
        $recipe = Recipe::findOrFail($data['recipe_id']);
        $result = $converter->convert($product, $recipe);

        $message = $result['converted']
            ? "«{$recipe->name}» reemplaza a «{$product->name}» en: ".implode(', ', $result['converted']).'.'
            : 'No se cambió ninguna receta.';
        if ($result['skipped']) {
            $message .= ' Sin cambiar: '.implode('; ', $result['skipped']).'.';
        }

        return back()->with('success', $message);
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
            'links' => $product->links()->get()->keyBy('provider_id'),
            'brands' => Product::distinctValues('brand')->merge(ProductLink::whereNotNull('brand')->distinct()->pluck('brand'))->unique()->sort()->values(),
            'postalCode' => Setting::value(Setting::POSTAL_CODE),
            // Recetas que lo usan (para cambiarlo por su receta si es algo casero)
            'usedIn' => Recipe::whereHas('ingredients', fn ($q) => $q->where('product_id', $product->id))->orderBy('name')->get(['id', 'name']),
            'recipes' => Recipe::orderBy('name')->get(['id', 'name']),
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

    public function update(Request $request, Product $product, ProductPhoto $photos)
    {
        $data = $this->validated($request, $product);
        AttachmentForm::validate($request);
        $product->update($data);
        AttachmentForm::apply($product, $request);
        $this->photoFromInternet($request, $product, $photos);

        return redirect()->route('products.show', $product)->with('success', 'Artículo actualizado.');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()->route('products.index')->with('success', 'Artículo eliminado (junto con su historial de precios).');
    }

    /**
     * Trae la foto del artículo de su página en las tiendas (o de Open Food Facts por su
     * código). `link_id` = solo de esa tienda. JSON para la ficha; si no, regresa con el aviso.
     */
    public function fetchPhoto(Request $request, Product $product, ProductPhoto $photos)
    {
        $data = $request->validate(['link_id' => ['nullable', 'integer']]);
        $link = isset($data['link_id']) ? $product->links()->whereKey($data['link_id'])->whereNotNull('url')->firstOrFail() : null;

        try {
            $result = $photos->fetch($product, $link);
        } catch (PriceFetchException $e) {
            $result = ['ok' => false, 'message' => $e->getMessage(), 'attachment' => null];
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $result['ok'],
                'message' => $result['message'],
                'photo' => $result['attachment']?->url(),
            ], $result['ok'] ? 200 : 422);
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** La foto que sugirió la búsqueda del código de barras (image_url), si no se subió otra. */
    private function photoFromInternet(Request $request, Product $product, ProductPhoto $photos): void
    {
        $url = (string) $request->input('image_url');
        if (! str_starts_with($url, 'https://') || $product->attachments()->where('kind', 'photo')->exists()) {
            return;
        }
        try {
            $photos->save($product, ProductPhoto::larger($url), 'internet');
        } catch (PriceFetchException) {
            // Sin foto: el artículo ya se guardó
        }
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        return ProductData::validate($request->all(), $product);
    }
}
