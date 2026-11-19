<?php

namespace App\Http\Controllers\Api;

use App\Api\Present;
use App\Enums\Unit;
use App\Models\Product;
use App\Models\ProductLink;
use App\Models\Provider;
use App\Models\Recipe;
use App\Services\SubrecipeConverter;
use App\Support\AttachmentForm;
use App\Support\Barcode;
use App\Support\ListGrid;
use App\Support\ProductData;
use Illuminate\Http\Request;

/** Artículos para la app móvil. Las reglas son las de la web (ProductData). */
class ProductController extends Controller
{
    private const WITH = ['prices.provider', 'attachments'];

    public function index(Request $request)
    {
        // Buscar (o escanear) un código de barras da solo ese artículo
        $barcode = Barcode::fromSearch($request->input('q'));
        $match = $barcode ? Product::findByBarcode($barcode) : null;

        // `detail=1`: la ficha completa de cada uno (historial, tiendas, fotos), para la copia sin conexión
        $detail = $request->boolean('detail');

        $products = Product::with($detail ? [...self::WITH, 'links.provider'] : self::WITH)
            ->when($barcode, fn ($q) => $q->whereKey($match?->id ?? 0))
            ->when(! $barcode && $request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('brand', 'like', $term));
            })
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->page($products, fn (Product $p) => $detail ? Present::productDetail($p) : Present::product($p), ['barcode' => $barcode]);
    }

    /**
     * Todos los artículos en corto (nombre, marca, presentación y códigos de barras): lo que
     * la app guarda para buscar y agregar a una lista sin conexión.
     */
    public function catalog()
    {
        return response()->json([
            'data' => Product::with('links')->orderBy('name')->get()->map(fn (Product $p) => ListGrid::product($p))->values(),
        ]);
    }

    /** Lo que necesitan los formularios: unidades, marcas y categorías ya usadas y las tiendas. */
    public function options()
    {
        return response()->json([
            'units' => Unit::meta(),
            'unit_names' => Unit::options(),
            'categories' => Product::distinctValues('category'),
            'brands' => Product::distinctValues('brand')->merge(ProductLink::whereNotNull('brand')->distinct()->pluck('brand'))->unique()->sort()->values(),
            'providers' => Provider::orderBy('name')->get()->map(fn (Provider $p) => Present::provider($p))->values(),
        ]);
    }

    public function show(Product $product)
    {
        return response()->json(['data' => $this->detail($product)]);
    }

    public function store(Request $request)
    {
        $product = Product::create(ProductData::validate($request->all()));

        return response()->json(['data' => $this->detail($product), 'message' => 'Artículo creado.'], 201);
    }

    public function update(Request $request, Product $product)
    {
        // Solo cambia lo que se manda
        $product->update(ProductData::validate($request->all() + ProductData::toInput($product), $product));

        return response()->json(['data' => $this->detail($product), 'message' => 'Artículo actualizado.']);
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->json(['message' => 'Artículo eliminado (junto con su historial de precios).']);
    }

    /**
     * El artículo en una tienda (dirección, marca y código): solo cambia lo que se manda;
     * si todo queda vacío, se quita.
     */
    public function saveLink(Request $request, Product $product, Provider $provider)
    {
        $request->merge(['barcode' => Barcode::normalize($request->input('barcode'))]);
        $data = $request->validate([
            'url' => ['sometimes', 'nullable', 'string', 'max:1000', 'url:https'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:100'],
            'barcode' => ['nullable', Barcode::RULE],
        ], [], ['url' => 'dirección', 'brand' => 'marca', 'barcode' => 'código de barras']);
        if (! $request->has('barcode')) {
            unset($data['barcode']);
        }

        $link = $product->links()->firstOrNew(['provider_id' => $provider->id]);
        if (array_key_exists('url', $data) && $link->url !== $data['url']) {
            // Otra página: lo que se leyó de la anterior ya no aplica
            $link->fill(['checked_at' => null, 'error' => null, 'store_name' => null, 'sku' => null]);
        }
        if (array_key_exists('brand', $data)) {
            $data['brand'] = Product::canonicalValue('brand', $data['brand']);
        }
        $link->fill($data);

        if (! $link->url && ! $link->brand && ! $link->barcode) {
            $link->exists && $link->delete();
        } else {
            $link->save();
        }

        return response()->json(['data' => $this->detail($product)]);
    }

    /**
     * Fotos del artículo (multipart): `files[]` nuevas con sus miniaturas `thumbs[]`,
     * `remove_attachments[]` y `attachment_order[]`, como el formulario de la web (AttachmentForm).
     */
    public function attachments(Request $request, Product $product)
    {
        AttachmentForm::validate($request);
        AttachmentForm::apply($product, $request);

        return response()->json(['data' => $this->detail($product)]);
    }

    /**
     * Cambia este artículo casero por su receta (`recipe_id`) en todas las recetas que lo usan
     * → `{converted[], skipped[], message}`. El artículo se conserva.
     */
    public function subrecipe(Request $request, Product $product, SubrecipeConverter $converter)
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

        return response()->json($result + ['message' => $message]);
    }

    /** @return array<string, mixed> */
    private function detail(Product $product): array
    {
        return Present::productDetail($product->fresh([...self::WITH, 'links.provider']));
    }
}
