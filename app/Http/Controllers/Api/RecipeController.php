<?php

namespace App\Http\Controllers\Api;

use App\Api\Present;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\Tag;
use App\Services\MenuShopper;
use App\Support\AttachmentForm;
use App\Support\Paginate;
use App\Support\RecipeData;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Recetas para la app móvil. Las reglas son las de la web (RecipeData) y el envío a la lista, MenuShopper. */
class RecipeController extends Controller
{
    /** Lo que necesita la ficha: el árbol para costear, los precios con su tienda, pasos, etiquetas y fotos. */
    private const WITH = ['ingredients.product.prices.provider', 'steps', 'tags', 'attachments', 'usedAsIngredient.recipe'];

    /**
     * Recetas (`q`, `category`, `tag`, `rating` mínima, `pantry=ready|almost`). Cada una trae
     * `pantry_check` (qué le falta de la despensa); con `detail=1`, la ficha completa.
     */
    public function index(Request $request)
    {
        $detail = $request->boolean('detail');
        $recipes = Recipe::withTree()->with($detail ? self::WITH : ['attachments', 'tags'])
            ->filter($request->string('q')->toString(), $request->string('category')->toString(), $request->string('tag')->toString(), $request->integer('rating') ?: null)
            ->orderBy('name')
            ->get();

        $checks = Recipe::pantryChecks($recipes);
        $pantry = match ($request->query('pantry')) {
            'ready', '1' => 'ready',
            'almost' => 'almost',
            default => null,
        };
        $all = $recipes;
        if ($pantry) {
            $recipes = Recipe::filterByPantry($recipes, $checks, $pantry);
        }

        return $this->page(
            Paginate::collection($recipes, $request, $this->perPage($request)),
            fn (Recipe $r) => $detail ? Present::recipeDetail($r) : Present::recipe($r, $checks[$r->id]),
            [
                'pantry' => $pantry,
                'counts' => [
                    'all' => $all->count(),
                    'ready' => $checks->filter(fn ($c) => $c && $c['ready'])->count(),
                    'almost' => $checks->filter(fn ($c) => $c && ! $c['ready'] && count($c['missing']) <= 2)->count(),
                ],
                'categories' => Recipe::categories(),
                'tags' => Tag::has('recipes')->orderBy('name')->pluck('name'),
            ],
        );
    }

    /**
     * Lo que necesita el formulario: artículos (con unidad y conversiones), recetas que pueden
     * ser sub-receta (`recipe_id` = la que se edita: no ella ni las que ya la usan), unidades,
     * categorías, etiquetas y comidas del menú.
     */
    public function options(Request $request)
    {
        $recipe = $request->filled('recipe_id') ? Recipe::findOrFail($request->integer('recipe_id')) : new Recipe;

        return response()->json(RecipeData::formOptions($recipe) + [
            'yield_units' => RecipeData::YIELD_UNITS,
            'categories' => Recipe::categories(),
            'tags' => Tag::names(),
            'meals' => MealPlanEntry::MEALS,
        ]);
    }

    public function show(Recipe $recipe)
    {
        return response()->json(['data' => $this->detail($recipe)]);
    }

    public function store(Request $request)
    {
        $recipe = RecipeData::save(new Recipe, RecipeData::validate($request->all()));

        return response()->json(['data' => $this->detail($recipe), 'message' => 'Receta guardada.'], 201);
    }

    /**
     * Solo cambia lo que se manda. `ingredients`, `steps` y `tags`, si se mandan, reemplazan la
     * lista completa (la ficha trae `input` con la receta en ese formato).
     */
    public function update(Request $request, Recipe $recipe)
    {
        $input = RecipeData::toInput($recipe);
        if ($request->has('instructions') && ! $request->has('steps')) {
            // El texto reemplaza los pasos
            unset($input['steps']);
        }
        RecipeData::save($recipe, RecipeData::validate($request->all() + $input, $recipe));

        return response()->json(['data' => $this->detail($recipe), 'message' => 'Receta actualizada.']);
    }

    public function destroy(Recipe $recipe)
    {
        $usedIn = $recipe->usedAsIngredient()->with('recipe')->get()->pluck('recipe.name')->filter()->unique();
        $recipe->delete();

        return response()->json(['message' => 'Receta eliminada.'.($usedIn->isNotEmpty()
            ? ' Quedó como texto libre en: '.$usedIn->join(', ').'.'
            : '')]);
    }

    /** Fotos (multipart), como en artículos: `files[]`, `thumbs[]`, `remove_attachments[]`, `attachment_order[]`. */
    public function attachments(Request $request, Recipe $recipe)
    {
        AttachmentForm::validate($request);
        AttachmentForm::apply($recipe, $request);

        return response()->json(['data' => $this->detail($recipe)]);
    }

    /** Revisión antes de mandar a una lista (`servings`): qué falta y qué cubre la despensa. */
    public function shopReview(Request $request, Recipe $recipe, MenuShopper $shopper)
    {
        $servings = min(999, max(1, $request->integer('servings') ?: $recipe->servings));
        $result = $shopper->needs([[$recipe->loadShoppingTree(), (float) $servings]]);

        return response()->json(['data' => self::review($shopper, $result, $recipe->name) + ['servings' => $servings]]);
    }

    /**
     * Manda los ingredientes a una lista (`list_id`; sin ella, una nueva). Sin revisión agrega
     * lo que falta; con `reviewed=1` + `buy[]` (ids de artículo), lo marcado (y corrige la despensa).
     */
    public function shop(Request $request, Recipe $recipe, MenuShopper $shopper)
    {
        $data = $request->validate(MenuShopper::RULES + ['servings' => ['nullable', 'integer', 'min:1', 'max:999']], [], ['list_id' => 'lista', 'servings' => 'porciones']);
        $portions = [[$recipe->loadShoppingTree(), (float) ($data['servings'] ?? $recipe->servings)]];

        return self::sent($shopper->send($portions, $data['list_id'] ?? null, $recipe->name, $request->boolean('reviewed') ? ($data['buy'] ?? []) : null),
            'Esta receta no tiene ingredientes ligados a artículos con cantidad.');
    }

    /**
     * La revisión (receta o semana): por artículo lo que se necesita, lo que hay en casa, lo
     * que falta y el estimado en la tienda más barata para esa cantidad.
     *
     * @return array<string, mixed>
     */
    public static function review(MenuShopper $shopper, array $result, string $newList): array
    {
        $rows = collect($shopper->reviewRows($result))->map(fn (array $row) => collect($row)->except('url')->all());

        return [
            'new_list' => $newList,
            'rows' => $rows->values()->all(),
            // Lo que costaría lo que viene marcado
            'estimated_total' => round($rows->where('checked', true)->sum(fn ($r) => $r['estimate'] ?? 0), 2),
            'unlinked' => $result['unlinked'],
            'lists' => ShoppingList::pending()->orderByDesc('shopped_on')->orderByDesc('id')->take(10)->get()
                ->map(fn (ShoppingList $l) => ['id' => $l->id, 'name' => $l->name, 'shopped_on' => $l->shopped_on->toDateString()])->all(),
        ];
    }

    /** Respuesta de MenuShopper::send(): 422 si no hay nada ligado a artículos. */
    public static function sent(array $sent, string $emptyMessage)
    {
        if ($sent['empty']) {
            throw ValidationException::withMessages(['list_id' => $emptyMessage]);
        }

        return response()->json([
            'message' => $sent['message'],
            'added' => $sent['count'],
            'skipped' => $sent['skipped'],
            'unlinked' => $sent['unlinked'],
            'list' => $sent['list'] ? Present::shoppingList($sent['list']->load(['items.price.provider', 'items.product.prices', 'tickets'])) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function detail(Recipe $recipe): array
    {
        return Present::recipeDetail(Recipe::withTree()->with(self::WITH)->findOrFail($recipe->id));
    }
}
