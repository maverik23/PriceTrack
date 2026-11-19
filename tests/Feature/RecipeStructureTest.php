<?php

namespace Tests\Feature;

use App\Mcp\PriceTrackServer;
use App\Mcp\Tools\CreateRecipe;
use App\Mcp\Tools\GetMenu;
use App\Mcp\Tools\GetRecipe;
use App\Mcp\Tools\ListRecipes;
use App\Mcp\Tools\PlanMeal;
use App\Mcp\Tools\SetMenuNote;
use App\Mcp\Tools\UpdateRecipe;
use App\Mcp\Tools\UseRecipeAsIngredient;
use App\Models\MealDayNote;
use App\Models\MealPlanEntry;
use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\Tag;
use App\Services\MenuShopper;
use App\Support\RecipeData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeStructureTest extends TestCase
{
    use RefreshDatabase;

    private Provider $walmart;

    private Product $papa;

    private Product $leche;

    private Product $pollo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->walmart = Provider::create(['name' => 'Walmart']);
        // $30/kg, 100 kcal por 100 g
        $this->papa = $this->product('Papa', 1, 'kg', 30, ['kcal' => 100, 'protein' => 2]);
        // $25/L
        $this->leche = $this->product('Leche', 1, 'l', 25, ['kcal' => 60, 'protein' => 3]);
        // $120/kg
        $this->pollo = $this->product('Pechuga', 1, 'kg', 120, ['kcal' => 120, 'protein' => 23]);
    }

    private function product(string $name, float $size, string $unit, float $price, array $extra = []): Product
    {
        $product = Product::create(['name' => $name, 'size' => $size, 'unit' => $unit, ...$extra]);
        Price::create([
            'product_id' => $product->id, 'provider_id' => $this->walmart->id, 'price' => $price,
            'pack_qty' => 1, 'size' => $size, 'unit' => $unit, 'observed_on' => today(),
        ]);

        return $product;
    }

    /** Crema: 1 kg de papa + 1 L de leche, rinde 4 porciones o 2000 g ($55). */
    private function crema(): Recipe
    {
        return RecipeData::save(new Recipe, RecipeData::validate([
            'name' => 'Crema de papa', 'servings' => 4, 'yield_qty' => 2, 'yield_unit' => 'kg',
            'ingredients' => [
                ['product_id' => $this->papa->id, 'quantity' => 1, 'unit' => 'kg'],
                ['product_id' => $this->leche->id, 'quantity' => 1, 'unit' => 'l'],
            ],
        ]));
    }

    public function test_form_saves_stages_sections_tags_rating_and_long_notes(): void
    {
        Tag::create(['name' => 'Mealprep']);
        $notes = "Primera línea\nSegunda línea ".str_repeat('x', 400);

        $this->post(route('recipes.store'), [
            // El formulario siempre manda la unidad del rendimiento, aunque no haya cantidad
            'name' => 'Albóndigas', 'servings' => 4, 'rating' => 4, 'notes' => $notes, 'yield_qty' => '', 'yield_unit' => 'g',
            'tags' => ['mealprep', 'Alta en proteína', 'alta en proteina'],
            'ingredients' => [
                ['product_id' => $this->pollo->id, 'quantity' => 500, 'unit' => 'g', 'section' => 'Albóndigas'],
                ['name' => 'Chipotle', 'section' => 'Salsa'],
                ['product_id' => $this->papa->id, 'quantity' => 200, 'unit' => 'g', 'section' => 'Albóndigas'],
                ['name' => ''],
            ],
            'steps' => [
                ['stage' => 'Salsa', 'body' => 'Licúa el chipotle.', 'minutes' => ''],
                ['stage' => 'Albóndigas', 'body' => 'Forma las albóndigas.', 'minutes' => ''],
                ['stage' => 'Albóndigas', 'body' => 'Cuécelas en la salsa.', 'minutes' => 25],
                ['stage' => '', 'body' => '  '],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $recipe = Recipe::with(['steps', 'tags', 'ingredients'])->latest('id')->firstOrFail();
        $this->assertSame(4, $recipe->rating);
        $this->assertSame($notes, $recipe->notes);
        $this->assertSame(['Alta en proteína', 'Mealprep'], $recipe->tags->pluck('name')->all());
        $this->assertSame(2, Tag::count());
        $this->assertSame([25], $recipe->steps->pluck('minutes')->filter()->values()->all());

        $stages = $recipe->stepStages();
        $this->assertSame(['Salsa', 'Albóndigas'], array_column($stages, 'stage'));
        $this->assertCount(2, $stages[1]['steps']);

        // Las secciones agrupan aunque los renglones no vengan seguidos
        $sections = $recipe->ingredientSections();
        $this->assertSame(['Albóndigas', 'Salsa'], array_column($sections, 'section'));
        $this->assertCount(2, $sections[0]['ingredients']);

        $this->get(route('recipes.show', $recipe))->assertOk()->assertSee('Licúa el chipotle.')->assertSee('Salsa')->assertSee('Mealprep');
        $this->get(route('recipes.edit', $recipe))->assertOk()->assertSee('Cuécelas en la salsa.', false);
        $this->get(route('recipes.index', ['tag' => 'Mealprep']))->assertOk()->assertSee('Albóndigas');
        $this->get(route('recipes.index', ['tag' => 'Otra']))->assertOk()->assertDontSee('Albóndigas');
        $this->get(route('recipes.index', ['rating' => 5]))->assertOk()->assertDontSee('href="'.route('recipes.show', $recipe).'"', false);

        // Calificar desde el encabezado
        $this->postJson(route('recipes.rate', $recipe), ['rating' => 5])->assertOk()->assertJsonPath('rating', 5);
        $this->postJson(route('recipes.rate', $recipe), ['rating' => 0])->assertJsonPath('rating', null);
        $this->postJson(route('recipes.rate', $recipe), ['rating' => 6])->assertJsonValidationErrors('rating');
    }

    public function test_instructions_text_is_still_accepted(): void
    {
        [$steps, $notes] = RecipeData::parseInstructions("# Salsa:\n1. Licúa todo (5 min)\nPaso 2: Cuela.\n\n## Montaje\nSirve caliente.\nNota — Rinde para 2 días.\n3) Decora");

        $this->assertSame([
            ['stage' => 'Salsa', 'body' => 'Licúa todo', 'minutes' => 5],
            ['stage' => 'Salsa', 'body' => 'Cuela.', 'minutes' => null],
            ['stage' => 'Montaje', 'body' => 'Sirve caliente.', 'minutes' => null],
            ['stage' => 'Montaje', 'body' => 'Decora', 'minutes' => null],
        ], $steps);
        $this->assertSame(['Rinde para 2 días.'], $notes);

        $this->post(route('recipes.store'), ['name' => 'Texto', 'servings' => 1, 'notes' => 'Mía', 'instructions' => "Uno\nNota — Dos"])->assertSessionHasNoErrors();
        $recipe = Recipe::latest('id')->firstOrFail();
        $this->assertSame(['Uno'], $recipe->steps->pluck('body')->all());
        $this->assertSame("Mía\n\nDos", $recipe->notes);
        $this->assertSame('Uno', $recipe->instructionsText());
    }

    public function test_subrecipes_expand_into_cost_nutrition_portion_and_shopping(): void
    {
        $crema = $this->crema();
        $this->assertEqualsWithDelta(55, $crema->loadShoppingTree()->cost(), 0.001);

        // 2 porciones de crema (= mitad de la receta) + 500 g de crema (= un cuarto) + 250 g de pollo
        $plato = RecipeData::save(new Recipe, RecipeData::validate([
            'name' => 'Plato', 'servings' => 2,
            'ingredients' => [
                ['subrecipe_id' => $crema->id, 'quantity' => 2, 'unit' => 'serving'],
                ['subrecipe_id' => $crema->id, 'quantity' => 500, 'unit' => 'g', 'section' => 'Para servir'],
                ['product_id' => $this->pollo->id, 'quantity' => 250, 'unit' => 'g'],
            ],
        ]))->loadShoppingTree();

        // 27.50 + 13.75 + 30
        $this->assertEqualsWithDelta(71.25, $plato->cost(), 0.001);
        $this->assertEqualsWithDelta(142.5, $plato->cost(4), 0.001);
        $this->assertSame(0, $plato->uncostedCount());

        // kcal: crema completa = 1000 + 600 = 1600 → 0.75 de la crema = 1200, + 300 del pollo
        $nutrition = $plato->nutrition();
        $this->assertEqualsWithDelta(1500, $nutrition['total']['kcal'], 0.01);
        $this->assertEqualsWithDelta(750, $nutrition['perServing']['kcal'], 0.01);
        $this->assertSame(0, $nutrition['missing']);

        // Peso: 1000 g (0.5 de 2000 g) + 500 g + 250 g = 1750 / 2 porciones
        $this->assertEqualsWithDelta(875, $plato->gramsPerServing(), 0.5);

        // A la lista van los ingredientes de la crema, no la crema
        $result = app(MenuShopper::class)->needs([[$plato, 2.0]]);
        $this->assertEqualsWithDelta(750, $result['needs'][$this->papa->id], 0.001);
        $this->assertEqualsWithDelta(750, $result['needs'][$this->leche->id], 0.001);
        $this->assertEqualsWithDelta(250, $result['needs'][$this->pollo->id], 0.001);

        $this->get(route('recipes.show', $plato))->assertOk()->assertSee('Crema de papa')->assertSee('Para servir');
        $this->get(route('recipes.show', $crema))->assertOk()->assertSee('Plato');
    }

    public function test_subrecipe_validation_and_cycles(): void
    {
        $crema = $this->crema();
        $plato = RecipeData::save(new Recipe, RecipeData::validate([
            'name' => 'Plato', 'servings' => 2,
            'ingredients' => [['subrecipe_id' => $crema->id, 'quantity' => 1, 'unit' => 'serving']],
        ]));

        // Sin cantidad, o en una unidad que no corresponde a lo que rinde
        $base = ['name' => 'X', 'servings' => 1];
        $this->post(route('recipes.store'), $base + ['ingredients' => [['subrecipe_id' => $crema->id]]])->assertSessionHasErrors('ingredients.0.quantity');
        $this->post(route('recipes.store'), $base + ['ingredients' => [['subrecipe_id' => $crema->id, 'quantity' => 1, 'unit' => 'cup']]])->assertSessionHasErrors('ingredients.0.unit');
        $this->post(route('recipes.store'), $base + ['ingredients' => [['subrecipe_id' => $plato->id, 'quantity' => 100, 'unit' => 'g']]])->assertSessionHasErrors('ingredients.0.unit');
        $this->post(route('recipes.store'), $base + ['ingredients' => [['product_id' => $this->papa->id, 'quantity' => 1, 'unit' => 'serving']]])->assertSessionHasErrors('ingredients.0.unit');

        // Ciclos: a sí misma, o la crema usando el plato que ya la usa
        $this->put(route('recipes.update', $crema), ['name' => 'Crema de papa', 'servings' => 4, 'ingredients' => [['subrecipe_id' => $crema->id, 'quantity' => 1, 'unit' => 'serving']]])
            ->assertSessionHasErrors('ingredients.0.subrecipe_id');
        $this->put(route('recipes.update', $crema), ['name' => 'Crema de papa', 'servings' => 4, 'ingredients' => [['subrecipe_id' => $plato->id, 'quantity' => 1, 'unit' => 'serving']]])
            ->assertSessionHasErrors('ingredients.0.subrecipe_id');

        // Aunque un ciclo llegara a la base, los cálculos no se cuelgan
        $crema->ingredients()->create(['subrecipe_id' => $plato->id, 'name' => 'Plato', 'quantity' => 1, 'unit' => 'serving', 'position' => 9]);
        $fresh = Recipe::findOrFail($plato->id)->loadShoppingTree();
        $this->assertNotNull($fresh->cost());
        $this->assertNotNull($fresh->nutrition());
        $this->assertNotEmpty(app(MenuShopper::class)->needs([[$fresh, 2.0]])['needs']);

        // El formulario de la crema no ofrece el plato como sub-receta
        $this->get(route('recipes.edit', $crema))->assertOk()->assertDontSee('"name":"Plato"', false);

        // Al borrar la sub-receta, el ingrediente queda como texto libre
        $plato2 = RecipeData::save(new Recipe, RecipeData::validate(['name' => 'Otro', 'servings' => 1, 'ingredients' => [['subrecipe_id' => $crema->id, 'quantity' => 1, 'unit' => 'serving']]]));
        $crema->ingredients()->where('subrecipe_id', $plato->id)->delete();
        $this->delete(route('recipes.destroy', $crema))->assertSessionHas('success', fn ($m) => str_contains($m, 'Otro'));
        $ingredient = $plato2->ingredients()->first();
        $this->assertNull($ingredient->subrecipe_id);
        $this->assertSame('Crema de papa', $ingredient->name);
    }

    public function test_casero_product_converts_to_subrecipe(): void
    {
        $casero = Product::create(['name' => 'Crema casera', 'size' => 1, 'unit' => 'kg', 'category' => 'Casero', 'cup_grams' => 250]);
        $pan = Product::create(['name' => 'Ciabatta', 'size' => 1, 'unit' => 'item', 'category' => 'Casero']);
        $crema = $this->crema();
        $ciabatta = RecipeData::save(new Recipe, RecipeData::validate(['name' => 'Ciabatta casera', 'servings' => 8, 'ingredients' => [['product_id' => $this->papa->id, 'quantity' => 100, 'unit' => 'g']]]));
        $comida = RecipeData::save(new Recipe, RecipeData::validate(['name' => 'Comida', 'servings' => 2, 'ingredients' => [
            ['product_id' => $casero->id, 'quantity' => 300, 'unit' => 'g', 'section' => 'Base'],
            ['product_id' => $casero->id, 'quantity' => 1, 'unit' => 'cup'],
            ['product_id' => $pan->id, 'quantity' => 2, 'unit' => 'item'],
        ]]));

        PriceTrackServer::tool(UseRecipeAsIngredient::class, ['product_id' => $casero->id, 'recipe_id' => $crema->id])
            ->assertOk()->assertSee('Comida');
        $rows = $comida->ingredients()->get();
        $this->assertSame([$crema->id, $crema->id], $rows->take(2)->pluck('subrecipe_id')->all());
        $this->assertSame(['g', 'g'], $rows->take(2)->map(fn ($r) => $r->unit->value)->all());
        $this->assertEqualsWithDelta(250, $rows[1]->quantity, 0.001);
        $this->assertSame('Base', $rows[0]->section);

        // Desde la ficha del artículo
        $this->post(route('products.subrecipe', $pan), ['recipe_id' => $ciabatta->id])->assertSessionHas('success');
        $row = $comida->ingredients()->where('position', 2)->first();
        $this->assertSame($ciabatta->id, $row->subrecipe_id);
        $this->assertSame('serving', $row->unit->value);
        $this->assertEqualsWithDelta(2, $row->quantity, 0.001);
    }

    public function test_mcp_recipe_tools_read_and_write_the_new_structure(): void
    {
        $crema = $this->crema();

        PriceTrackServer::tool(CreateRecipe::class, [
            'name' => 'Pollo con crema', 'servings' => 2, 'tags' => ['Mealprep'], 'rating' => 5,
            'ingredients' => [
                ['product_id' => $this->pollo->id, 'quantity' => 400, 'unit' => 'g', 'section' => 'Pollo'],
                ['subrecipe_id' => $crema->id, 'quantity' => 1, 'unit' => 'serving', 'section' => 'Salsa'],
            ],
            'steps' => [['text' => 'Dora el pollo.', 'stage' => 'Pollo', 'minutes' => 10], ['text' => 'Baña con la crema.']],
        ])->assertOk()->assertSee('Pollo con crema');
        $recipe = Recipe::where('name', 'Pollo con crema')->firstOrFail();
        $this->assertSame(2, $recipe->steps()->count());

        PriceTrackServer::tool(GetRecipe::class, ['recipe_id' => $recipe->id, 'servings' => 4])->assertOk()
            ->assertSee('"stage":"Pollo"', false)->assertSee('"section":"Salsa"', false)->assertSee('"subrecipe_id":'.$crema->id, false)
            ->assertSee('"minutes":10', false)->assertSee('Mealprep')->assertSee('800 g');

        // Solo cambia lo que se manda
        PriceTrackServer::tool(UpdateRecipe::class, ['recipe_id' => $recipe->id, 'rating' => 3, 'notes' => "Línea 1\nLínea 2"])->assertOk();
        $recipe->refresh();
        $this->assertSame(3, $recipe->rating);
        $this->assertSame(2, $recipe->ingredients()->count());
        $this->assertSame(2, $recipe->steps()->count());
        $this->assertSame(['Mealprep'], $recipe->tags()->pluck('name')->all());

        PriceTrackServer::tool(UpdateRecipe::class, ['recipe_id' => $recipe->id, 'instructions' => "# Final\nSirve."])->assertOk();
        $this->assertSame(['Final'], $recipe->steps()->pluck('stage')->all());
        PriceTrackServer::tool(UpdateRecipe::class, ['recipe_id' => $crema->id, 'ingredients' => [['subrecipe_id' => $recipe->id, 'quantity' => 1, 'unit' => 'serving']]])
            ->assertHasErrors(['ya usa']);
        PriceTrackServer::tool(UpdateRecipe::class, ['recipe_id' => 999999])->assertHasErrors(['No existe']);

        PriceTrackServer::tool(ListRecipes::class, ['tag' => 'mealprep'])->assertOk()->assertSee('Pollo con crema')->assertDontSee('"name":"Crema de papa"', false);
        PriceTrackServer::tool(ListRecipes::class, ['min_rating' => 4])->assertOk()->assertDontSee('Pollo con crema');
    }

    public function test_snack_and_day_notes_in_the_menu(): void
    {
        $crema = $this->crema();
        $monday = today()->startOfWeek();

        $this->post(route('menu.store'), ['planned_on' => $monday->toDateString(), 'meal' => 'snack', 'recipe_id' => $crema->id])->assertSessionHasNoErrors();
        $this->putJson(route('menu.note', $monday->toDateString()), ['body' => 'Mealprep: cocer arroz y pollo'])->assertOk()->assertJsonPath('body', 'Mealprep: cocer arroz y pollo');
        $this->putJson(route('menu.note', $monday->toDateString()), ['body' => 'Mealprep: cocer arroz'])->assertOk();
        $this->assertSame(1, MealDayNote::count());

        $this->get(route('menu.index', ['week' => $monday->toDateString()]))->assertOk()->assertSee('Merienda')->assertSee('Mealprep: cocer arroz');

        $this->put(route('menu.note', $monday->toDateString()), ['body' => ''])->assertRedirect();
        $this->assertSame(0, MealDayNote::count());

        PriceTrackServer::tool(SetMenuNote::class, ['date' => $monday->toDateString(), 'note' => 'Comprar tortillas'])->assertOk();
        PriceTrackServer::tool(PlanMeal::class, ['planned_on' => $monday->toDateString(), 'meal' => 'snack', 'title' => 'Fruta'])->assertOk()->assertSee('Merienda');
        PriceTrackServer::tool(GetMenu::class, ['week' => $monday->toDateString()])->assertOk()->assertSee('Comprar tortillas')->assertSee('Fruta');
        $this->assertSame(2, MealPlanEntry::where('meal', 'snack')->count());

        // El menú de la semana manda los ingredientes de la sub-receta
        $this->post(route('menu.shop'), ['week' => $monday->toDateString()])->assertRedirect();
        $this->assertEqualsCanonicalizing([$this->papa->id, $this->leche->id], ShoppingList::firstOrFail()->items()->pluck('product_id')->all());
    }
}
