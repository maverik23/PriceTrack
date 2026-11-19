<?php

namespace App\Http\Controllers;

use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\ShoppingList;
use App\Models\Tag;
use App\Services\MenuShopper;
use App\Support\AttachmentForm;
use App\Support\Paginate;
use App\Support\RecipeData;
use Illuminate\Http\Request;

class RecipeController extends Controller
{
    public function index(Request $request)
    {
        $recipes = Recipe::withTree()->with(['attachments', 'tags'])
            ->filter($request->string('q')->toString(), $request->string('category')->toString(), $request->string('tag')->toString(), $request->integer('rating') ?: null)
            ->orderBy('name')
            ->get();

        // Qué se puede hacer con lo de la despensa (?pantry=1: solo esas; ?pantry=almost: falta 1 o 2 cosas)
        $checks = Recipe::pantryChecks($recipes);
        $pantry = in_array($request->query('pantry'), ['1', 'almost'], true) ? $request->query('pantry') : null;
        if ($pantry) {
            $recipes = Recipe::filterByPantry($recipes, $checks, $pantry === '1' ? 'ready' : 'almost');
        }

        return view('recipes.index', [
            'recipes' => Paginate::collection($recipes, $request),
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
        $data = $request->validate(MenuShopper::RULES + ['servings' => ['nullable', 'integer', 'min:1', 'max:999']]);

        $portions = [[$recipe->loadShoppingTree(), (float) ($data['servings'] ?? $recipe->servings)]];
        $sent = $shopper->send($portions, $data['list_id'] ?? null, $recipe->name, $request->boolean('reviewed') ? ($data['buy'] ?? []) : null);
        if ($sent['empty']) {
            return back()->withErrors(['list_id' => 'Esta receta no tiene ingredientes ligados a artículos con cantidad.']);
        }

        return ($sent['list'] ? redirect()->route('lists.show', $sent['list']) : redirect()->route('recipes.show', $recipe))
            ->with('success', $sent['message']);
    }

    private function recentLists()
    {
        return ShoppingList::pending()->orderByDesc('shopped_on')->orderByDesc('id')->take(10)->get();
    }

    private function formData(Recipe $recipe): array
    {
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
            'form' => RecipeData::formOptions($recipe) + [
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
