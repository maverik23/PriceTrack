<?php

namespace App\Http\Controllers;

use App\Enums\Unit;
use App\Models\MealPlanEntry;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Services\MenuShopper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecipeController extends Controller
{
    public function index(Request $request)
    {
        $recipes = Recipe::with('ingredients.product.prices')
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q')->trim().'%'))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->orderBy('name')
            ->get();

        return view('recipes.index', [
            'recipes' => $recipes,
            'categories' => Recipe::categories(),
        ]);
    }

    public function create()
    {
        return view('recipes.create', $this->formData(new Recipe(['servings' => 4])));
    }

    public function store(Request $request)
    {
        $recipe = DB::transaction(function () use ($request) {
            [$data, $ingredients] = $this->validated($request);
            $recipe = Recipe::create($data);
            $recipe->ingredients()->createMany($ingredients);

            return $recipe;
        });

        return redirect()->route('recipes.show', $recipe)->with('success', 'Receta guardada.');
    }

    public function show(Request $request, Recipe $recipe)
    {
        $recipe->load('ingredients.product.prices.provider');
        $servings = min(999, max(1, $request->integer('servings') ?: $recipe->servings));

        return view('recipes.show', [
            'recipe' => $recipe,
            'servings' => $servings,
            'factor' => $recipe->factor($servings),
            'lists' => $this->recentLists(),
            'meals' => MealPlanEntry::MEALS,
        ]);
    }

    public function edit(Recipe $recipe)
    {
        return view('recipes.edit', $this->formData($recipe->load('ingredients')));
    }

    public function update(Request $request, Recipe $recipe)
    {
        DB::transaction(function () use ($request, $recipe) {
            [$data, $ingredients] = $this->validated($request);
            $recipe->update($data);
            $recipe->ingredients()->delete();
            $recipe->ingredients()->createMany($ingredients);
        });

        return redirect()->route('recipes.show', $recipe)->with('success', 'Receta actualizada.');
    }

    public function destroy(Recipe $recipe)
    {
        $recipe->delete();

        return redirect()->route('recipes.index')->with('success', 'Receta eliminada.');
    }

    /** Manda los ingredientes (para las porciones indicadas) a una lista de compras. */
    public function shop(Request $request, Recipe $recipe, MenuShopper $shopper)
    {
        $data = $request->validate([
            'servings' => ['nullable', 'integer', 'min:1', 'max:999'],
            'list_id' => ['nullable', 'exists:shopping_lists,id'],
        ]);

        $recipe->load('ingredients.product');
        $result = $shopper->needs([[$recipe, (float) ($data['servings'] ?? $recipe->servings)]]);
        if (! $result['needs']) {
            return back()->withErrors(['list_id' => 'Esta receta no tiene ingredientes ligados a artículos con cantidad.']);
        }

        $list = $shopper->resolveList($data['list_id'] ?? null, $recipe->name);
        $count = $shopper->addToList($list, $result['needs']);

        return redirect()->route('lists.show', $list)->with('success', $shopper->summary($count, $result['unlinked']));
    }

    private function recentLists()
    {
        return ShoppingList::orderByDesc('shopped_on')->orderByDesc('id')->take(10)->get();
    }

    private function formData(Recipe $recipe): array
    {
        $products = Product::orderBy('name')->get();

        return [
            'recipe' => $recipe,
            'categories' => Recipe::categories(),
            'form' => [
                'products' => $products->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->fullName(),
                    'unit' => $p->unit->value,
                ])->all(),
                'units' => Unit::meta(),
                'ingredients' => collect(old('ingredients', $recipe->ingredients->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'name' => $i->product_id ? '' : $i->name,
                    'quantity' => num($i->quantity),
                    'unit' => $i->unit?->value,
                    'note' => $i->note,
                ])->all()))->map(fn ($row) => [
                    'product_id' => (string) ($row['product_id'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'quantity' => (string) ($row['quantity'] ?? ''),
                    'unit' => (string) ($row['unit'] ?? ''),
                    'note' => (string) ($row['note'] ?? ''),
                ])->values()->all(),
            ],
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>} receta e ingredientes
     */
    private function validated(Request $request): array
    {
        // Los renglones vacíos del formulario no cuentan
        $rows = collect($request->input('ingredients', []))
            ->filter(fn ($row) => is_array($row) && (filled($row['product_id'] ?? null) || filled($row['name'] ?? null)))
            ->values()->all();
        $request->merge(['ingredients' => $rows]);

        $data = $request->validate([
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
            'ingredients.*.unit' => ['nullable', Rule::enum(Unit::class)],
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
            $unit = filled($row['unit'] ?? null) ? Unit::from($row['unit']) : null;
            $quantity = filled($row['quantity'] ?? null) ? (float) $row['quantity'] : null;

            if ($product) {
                // Con artículo la cantidad es obligatoria y de su misma dimensión: de ahí salen costo y lista
                if (! $quantity || ! $unit) {
                    throw ValidationException::withMessages(["ingredients.$i.quantity" => "Indica cantidad y unidad de «{$product->name}»."]);
                }
                if (! $unit->isCompatibleWith($product->unit)) {
                    $allowed = implode(', ', array_map(fn (Unit $u) => $u->label(), $product->unit->compatibleUnits()));
                    throw ValidationException::withMessages(["ingredients.$i.unit" => "«{$product->name}» se mide en {$allowed}."]);
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
