<?php

namespace App\Http\Controllers;

use App\Enums\IngredientUnit;
use App\Models\MealPlanEntry;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\ShoppingList;
use App\Models\Tag;
use App\Services\MenuShopper;
use App\Support\AttachmentForm;
use App\Support\RecipeData;
use Illuminate\Http\Request;

class RecipeController extends Controller
{
    public function index(Request $request)
    {
        $recipes = Recipe::withTree()->with(['attachments', 'tags'])
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q')->trim().'%'))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('tag'), fn ($q) => $q->whereHas('tags', fn ($t) => $t->where('name', $request->string('tag'))))
            ->when($request->filled('rating'), fn ($q) => $q->where('rating', '>=', $request->integer('rating')))
            ->orderBy('name')
            ->get();

        // Qué se puede hacer con lo de la despensa (?pantry=1: solo esas; ?pantry=almost: falta 1 o 2 cosas)
        $checks = $recipes->mapWithKeys(fn (Recipe $r) => [$r->id => $r->ingredients->isEmpty() ? null : $r->pantryCheck()]);
        $pantry = in_array($request->query('pantry'), ['1', 'almost'], true) ? $request->query('pantry') : null;
        if ($pantry) {
            $recipes = $recipes->filter(fn (Recipe $r) => ($check = $checks[$r->id]) && ($pantry === '1'
                ? $check['ready']
                : ! $check['ready'] && count($check['missing']) <= 2))->values();
        }

        return view('recipes.index', [
            'recipes' => $recipes,
            'checks' => $checks,
            'pantry' => $pantry,
            'readyCount' => $checks->filter(fn ($c) => $c && $c['ready'])->count(),
            'almostCount' => $checks->filter(fn ($c) => $c && ! $c['ready'] && count($c['missing']) <= 2)->count(),
            'categories' => Recipe::categories(),
            'tags' => Tag::has('recipes')->orderBy('name')->pluck('name'),
        ]);
    }

    public function create()
    {
        return view('recipes.create', $this->formData(new Recipe(['servings' => 4])));
    }

    public function store(Request $request)
    {
        AttachmentForm::validate($request);
        $recipe = RecipeData::save(new Recipe, RecipeData::validate($request->all()));
        AttachmentForm::apply($recipe, $request);

        return redirect()->route('recipes.show', $recipe)->with('success', 'Receta guardada.');
    }

    public function show(Request $request, Recipe $recipe)
    {
        $recipe->loadShoppingTree()->load(['ingredients.product.prices.provider', 'steps', 'tags', 'attachments', 'usedAsIngredient.recipe']);
        $servings = min(999, max(1, $request->integer('servings') ?: $recipe->servings));

        return view('recipes.show', [
            'recipe' => $recipe,
            'servings' => $servings,
            'factor' => $recipe->factor($servings),
            'meals' => MealPlanEntry::MEALS,
        ]);
    }

    public function edit(Recipe $recipe)
    {
        return view('recipes.edit', $this->formData($recipe->load(['ingredients', 'steps', 'tags'])));
    }

    public function update(Request $request, Recipe $recipe)
    {
        AttachmentForm::validate($request);
        RecipeData::save($recipe, RecipeData::validate($request->all(), $recipe));
        AttachmentForm::apply($recipe, $request);

        return redirect()->route('recipes.show', $recipe)->with('success', 'Receta actualizada.');
    }

    public function destroy(Recipe $recipe)
    {
        $usedIn = $recipe->usedAsIngredient()->with('recipe')->get()->pluck('recipe.name')->filter()->unique();
        $recipe->delete();

        return redirect()->route('recipes.index')->with('success', 'Receta eliminada.'.($usedIn->isNotEmpty()
            ? ' Quedó como texto libre en: '.$usedIn->join(', ').'.'
            : ''));
    }

    /** Calificación de 1 a 5 desde el encabezado (0 la quita). */
    public function rate(Request $request, Recipe $recipe)
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'min:0', 'max:5']], [], ['rating' => 'calificación']);
        $recipe->update(['rating' => $data['rating'] ?: null]);

        if ($request->expectsJson()) {
            return response()->json(['rating' => $recipe->rating]);
        }

        return back()->with('success', $recipe->rating ? "Calificada con {$recipe->rating} de 5." : 'Calificación quitada.');
    }

    /** Revisión antes de mandar a una lista: qué falta y qué ya está en la despensa. */
    public function shopReview(Request $request, Recipe $recipe, MenuShopper $shopper)
    {
        $servings = min(999, max(1, $request->integer('servings') ?: $recipe->servings));
        $result = $shopper->needs([[$recipe->loadShoppingTree(), (float) $servings]]);

        return view('recipes.shop', [
            'title' => $recipe->name,
            'subtitle' => $servings.' '.($servings === 1 ? 'porción' : 'porciones'),
            'back' => route('recipes.show', [$recipe, 'servings' => $servings === $recipe->servings ? null : $servings]),
            'action' => route('recipes.shop', $recipe),
            'hidden' => ['servings' => $servings],
            'newList' => $recipe->name,
            'rows' => $shopper->reviewRows($result),
            'unlinked' => $result['unlinked'],
            'lists' => $this->recentLists(),
        ]);
    }

    /** Manda los ingredientes (para las porciones indicadas) a una lista de compras. */
    public function shop(Request $request, Recipe $recipe, MenuShopper $shopper)
    {
        $data = $request->validate([
            'servings' => ['nullable', 'integer', 'min:1', 'max:999'],
            'list_id' => ['nullable', 'exists:shopping_lists,id'],
            'buy' => ['nullable', 'array'],
            'buy.*' => ['integer'],
        ]);

        $result = $shopper->needs([[$recipe->loadShoppingTree(), (float) ($data['servings'] ?? $recipe->servings)]]);
        if (! $result['rows']) {
            return back()->withErrors(['list_id' => 'Esta receta no tiene ingredientes ligados a artículos con cantidad.']);
        }

        // Con revisión (reviewed=1) se agrega lo marcado; sin ella, lo que falta en la despensa
        [$add, $skipped] = $shopper->chosen($result, $request->boolean('reviewed') ? ($data['buy'] ?? []) : null);
        if (! $add) {
            return redirect()->route('recipes.show', $recipe)->with('success', $shopper->summary(0, $skipped, $result['unlinked']));
        }

        $list = $shopper->resolveList($data['list_id'] ?? null, $recipe->name);
        $count = $shopper->addToList($list, $add);

        return redirect()->route('lists.show', $list)->with('success', $shopper->summary($count, $skipped, $result['unlinked']));
    }

    private function recentLists()
    {
        return ShoppingList::pending()->orderByDesc('shopped_on')->orderByDesc('id')->take(10)->get();
    }

    private function formData(Recipe $recipe): array
    {
        $products = Product::orderBy('name')->get();
        // Recetas que se pueden usar como ingrediente: no ella misma ni las que ya la usan
        $blocked = $recipe->exists ? RecipeIngredient::where('subrecipe_id', $recipe->id)->pluck('recipe_id')->all() : [];
        $subrecipes = Recipe::when($recipe->exists, fn ($q) => $q->where('id', '!=', $recipe->id))->orderBy('name')->get()
            ->reject(fn (Recipe $r) => $recipe->exists && (in_array($r->id, $blocked, true) || in_array($recipe->id, Recipe::descendantIds($r->id), true)));

        $old = old('ingredients');
        $ingredients = $old !== null ? $old : $recipe->ingredients->map(fn (RecipeIngredient $i) => [
            'product_id' => $i->product_id,
            'subrecipe_id' => $i->subrecipe_id,
            'name' => $i->product_id || $i->subrecipe_id ? '' : $i->name,
            'quantity' => num($i->quantity),
            'unit' => $i->unit?->value,
            'note' => $i->note,
            'section' => $i->section,
        ])->all();
        $steps = old('steps', $recipe->steps->map(fn (RecipeStep $s) => [
            'stage' => $s->stage, 'body' => $s->body, 'minutes' => $s->minutes,
        ])->all());

        return [
            'recipe' => $recipe,
            'categories' => Recipe::categories(),
            'tagOptions' => Tag::names(),
            'tags' => old('tags', $recipe->tags->pluck('name')->all()),
            'form' => [
                'products' => $products->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->fullName(),
                    'unit' => $p->unit->value,
                    'dimension' => $p->unit->dimension(),
                    'piece' => $p->piece_weight,
                    'cup' => $p->cup_grams,
                ])->all(),
                'recipes' => $subrecipes->map(fn (Recipe $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'servings' => $r->servings,
                    'yield' => $r->yieldDimension(),
                    'yieldLabel' => $r->yieldLabel(),
                ])->values()->all(),
                'units' => IngredientUnit::meta(),
                'ingredients' => collect($ingredients)->map(fn ($row) => [
                    'product_id' => (string) ($row['product_id'] ?? ''),
                    'subrecipe_id' => (string) ($row['subrecipe_id'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'quantity' => (string) ($row['quantity'] ?? ''),
                    'unit' => (string) ($row['unit'] ?? ''),
                    'note' => (string) ($row['note'] ?? ''),
                    'section' => (string) ($row['section'] ?? ''),
                ])->values()->all(),
                'steps' => collect($steps)->map(fn ($row) => [
                    'stage' => (string) ($row['stage'] ?? ''),
                    'body' => (string) ($row['body'] ?? ''),
                    'minutes' => (string) ($row['minutes'] ?? ''),
                ])->values()->all(),
            ],
        ];
    }
}
