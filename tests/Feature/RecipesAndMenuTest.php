<?php

namespace Tests\Feature;

use App\Models\MealPlanEntry;
use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\ShoppingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipesAndMenuTest extends TestCase
{
    use RefreshDatabase;

    private Provider $walmart;

    private Product $arroz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->walmart = Provider::create(['name' => 'Walmart']);
        $this->arroz = $this->product('Arroz 1 kg', 1, 'kg', 40);
    }

    private function product(string $name, float $size, string $unit, ?float $price = null): Product
    {
        $product = Product::create(['name' => $name, 'size' => $size, 'unit' => $unit]);
        if ($price !== null) {
            Price::create([
                'product_id' => $product->id, 'provider_id' => $this->walmart->id, 'price' => $price,
                'pack_qty' => 1, 'size' => $size, 'unit' => $unit, 'observed_on' => today(),
            ]);
        }

        return $product;
    }

    private function recipe(array $ingredients, array $data = []): Recipe
    {
        $this->post(route('recipes.store'), [
            'name' => 'Receta', 'servings' => 4, ...$data, 'ingredients' => $ingredients,
        ])->assertSessionHasNoErrors();

        return Recipe::latest('id')->firstOrFail();
    }

    public function test_recipe_cost_menu_and_shopping_list_flow(): void
    {
        $this->get(route('recipes.index'))->assertOk();
        $this->get(route('recipes.create'))->assertOk();

        $this->post(route('recipes.store'), [
            'name' => 'Arroz rojo', 'servings' => 4, 'category' => 'Guisados', 'instructions' => "Uno\nDos",
            'ingredients' => [
                ['product_id' => $this->arroz->id, 'quantity' => 250, 'unit' => 'g'],
                ['name' => 'Sal', 'note' => 'al gusto'],
                ['name' => ''],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $recipe = Recipe::firstOrFail();
        $this->assertCount(2, $recipe->ingredients);
        $this->assertSame(['Uno', 'Dos'], $recipe->steps());
        $this->assertEqualsWithDelta(10.0, $recipe->cost(), 0.001);
        $this->assertEqualsWithDelta(20.0, $recipe->cost(8), 0.001);
        $this->assertEqualsWithDelta(2.5, $recipe->costPerServing(), 0.001);
        $this->assertSame(1, $recipe->uncostedCount());

        $this->get(route('recipes.show', $recipe))->assertOk()->assertSee('Arroz rojo');
        $this->get(route('recipes.show', [$recipe, 'servings' => 8]))->assertOk()->assertSee('500 g');
        $this->get(route('recipes.edit', $recipe))->assertOk();
        $this->get(route('recipes.index'))->assertOk()->assertSee('Arroz rojo');

        $this->post(route('menu.store'), ['planned_on' => today()->toDateString(), 'meal' => 'lunch', 'recipe_id' => $recipe->id, 'servings' => 8])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('menu.store'), ['planned_on' => today()->toDateString(), 'meal' => 'dinner', 'title' => 'Sobras'])
            ->assertSessionHasNoErrors();
        $this->get(route('menu.index'))->assertOk()->assertSee('Arroz rojo')->assertSee('Sobras')->assertSee('$20.00');
        $this->get(route('dashboard'))->assertOk()->assertSee('Hoy se come')->assertSee('Sobras');

        $this->post(route('menu.shop'), ['week' => today()->toDateString()])->assertSessionHasNoErrors()->assertRedirect();
        $list = ShoppingList::firstOrFail();
        $this->assertEqualsWithDelta(0.5, $list->items()->first()->quantity, 0.0001);

        // Se suma a lo que ya tenga la lista
        $this->post(route('recipes.shop', $recipe), ['list_id' => $list->id])->assertRedirect(route('lists.show', $list));
        $this->assertEqualsWithDelta(0.75, $list->items()->first()->quantity, 0.0001);
        $this->get(route('lists.show', $list))->assertOk();

        $this->delete(route('menu.destroy', MealPlanEntry::first()))->assertRedirect();
        $this->assertSame(1, MealPlanEntry::count());
    }

    public function test_linked_ingredients_need_quantity_and_the_same_dimension(): void
    {
        $this->post(route('recipes.store'), [
            'name' => 'X', 'servings' => 1,
            'ingredients' => [['product_id' => $this->arroz->id, 'quantity' => 1, 'unit' => 'ml']],
        ])->assertSessionHasErrors('ingredients.0.unit');

        $this->post(route('recipes.store'), [
            'name' => 'X', 'servings' => 1,
            'ingredients' => [['name' => 'Sal'], ['product_id' => $this->arroz->id]],
        ])->assertSessionHasErrors('ingredients.1.quantity');

        $this->post(route('recipes.store'), [
            'name' => 'X', 'servings' => 1,
            'ingredients' => [['product_id' => $this->arroz->id, 'quantity' => 0, 'unit' => 'g']],
        ])->assertSessionHasErrors('ingredients.0.quantity');

        $this->assertSame(0, Recipe::count());

        // Lo escrito vuelve al formulario
        $this->from(route('recipes.create'))->post(route('recipes.store'), [
            'name' => 'Con error', 'servings' => 2,
            'ingredients' => [['product_id' => $this->arroz->id, 'quantity' => 3, 'unit' => 'item', 'note' => 'lavado']],
        ])->assertRedirect(route('recipes.create'));
        $this->get(route('recipes.create'))->assertSee('Con error')->assertSee('lavado')->assertSee('se mide en');

        // Texto libre: cantidad sin unidad está bien
        $this->post(route('recipes.store'), [
            'name' => 'Libre', 'servings' => 1, 'ingredients' => [['name' => 'Cebolla', 'quantity' => 1]],
        ])->assertSessionHasNoErrors();
        $this->assertNull(Recipe::firstOrFail()->cost());
    }

    public function test_editing_replaces_ingredients(): void
    {
        $frijol = $this->product('Frijol 900 g', 900, 'g', 45);
        $recipe = $this->recipe([
            ['product_id' => $this->arroz->id, 'quantity' => 250, 'unit' => 'g'],
            ['name' => 'Sal'],
        ]);

        $this->put(route('recipes.update', $recipe), [
            'name' => 'Frijoles', 'servings' => 6,
            'ingredients' => [['product_id' => $frijol->id, 'quantity' => 0.45, 'unit' => 'kg', 'note' => 'remojados']],
        ])->assertSessionHasNoErrors()->assertRedirect(route('recipes.show', $recipe));

        $recipe->refresh();
        $this->assertSame('Frijoles', $recipe->name);
        $this->assertCount(1, $recipe->ingredients);
        $this->assertSame($frijol->id, $recipe->ingredients[0]->product_id);
        $this->assertSame('remojados', $recipe->ingredients[0]->note);
        $this->assertEqualsWithDelta(22.5, $recipe->cost(), 0.001);
    }

    public function test_deleting_a_product_keeps_the_ingredient_as_text(): void
    {
        $recipe = $this->recipe([['product_id' => $this->arroz->id, 'quantity' => 250, 'unit' => 'g']]);

        $this->arroz->prices()->delete();
        $this->arroz->delete();

        $ingredient = $recipe->fresh()->ingredients->first();
        $this->assertNull($ingredient->product_id);
        $this->assertSame('Arroz 1 kg', $ingredient->displayName());
        $this->assertNull($recipe->fresh()->cost());
        $this->get(route('recipes.show', $recipe))->assertOk()->assertSee('Arroz 1 kg')->assertSee('Sin artículo');
    }

    public function test_an_ingredient_whose_product_changed_dimension_is_not_costed_nor_shopped(): void
    {
        $recipe = $this->recipe([['product_id' => $this->arroz->id, 'quantity' => 250, 'unit' => 'g']]);
        $this->arroz->update(['unit' => 'item', 'size' => 1]);

        $this->assertNull($recipe->fresh()->cost());
        $this->post(route('recipes.shop', $recipe))->assertSessionHasErrors('list_id');
        $this->assertSame(0, ShoppingList::count());
    }

    public function test_week_menu_adds_up_products_shared_by_several_recipes(): void
    {
        $huevo = $this->product('Huevo 30 pzas', 30, 'item', 90);
        $arrozRojo = $this->recipe([['product_id' => $this->arroz->id, 'quantity' => 250, 'unit' => 'g']], ['name' => 'Arroz rojo']);
        $arrozHuevo = $this->recipe([
            ['product_id' => $this->arroz->id, 'quantity' => 0.5, 'unit' => 'kg'],
            ['product_id' => $huevo->id, 'quantity' => 6, 'unit' => 'item'],
            ['name' => 'Cilantro'],
        ], ['name' => 'Arroz con huevo', 'servings' => 2]);

        $monday = today()->startOfWeek();
        MealPlanEntry::create(['planned_on' => $monday, 'meal' => 'lunch', 'recipe_id' => $arrozRojo->id]);
        MealPlanEntry::create(['planned_on' => $monday->copy()->addDays(2), 'meal' => 'dinner', 'recipe_id' => $arrozHuevo->id, 'servings' => 4]);
        // El domingo es parte de la semana; el lunes siguiente ya no
        MealPlanEntry::create(['planned_on' => $monday->copy()->addDays(6), 'meal' => 'other', 'title' => 'Pozole del domingo']);
        // Otra semana: no cuenta
        MealPlanEntry::create(['planned_on' => $monday->copy()->addWeek(), 'meal' => 'lunch', 'recipe_id' => $arrozRojo->id]);

        $this->post(route('menu.shop'), ['week' => $monday->copy()->addDays(3)->toDateString()])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'Cilantro'));

        $items = ShoppingList::firstOrFail()->items->keyBy('product_id');
        $this->assertCount(2, $items);
        // 250 g + 1 kg (receta de 2 porciones para 4) = 1.25 de arroz de 1 kg
        $this->assertEqualsWithDelta(1.25, $items[$this->arroz->id]->quantity, 0.0001);
        // 12 huevos de un paquete de 30
        $this->assertEqualsWithDelta(0.4, $items[$huevo->id]->quantity, 0.0001);

        // 10 + 40 (1 kg de arroz) + 36 (12 huevos) = 86
        $this->get(route('menu.index', ['week' => $monday->toDateString()]))->assertOk()->assertSee('$86.00')->assertSee('Pozole del domingo');
    }

    public function test_invalid_week_falls_back_to_this_week(): void
    {
        $this->post(route('menu.store'), ['planned_on' => today()->toDateString(), 'meal' => 'lunch', 'title' => 'Tacos']);

        $this->get(route('menu.index', ['week' => 'no-es-fecha']))->assertOk()->assertSee('Tacos');
        $this->get(route('menu.index', ['week' => ['x']]))->assertOk()->assertSee('Tacos');
        $this->get(route('menu.index', ['week' => today()->addWeek()->toDateString()]))->assertOk()->assertDontSee('Tacos');
    }

    public function test_menu_entries_need_a_recipe_or_a_title(): void
    {
        $this->post(route('menu.store'), ['planned_on' => today()->toDateString(), 'meal' => 'lunch'])
            ->assertSessionHasErrors(['recipe_id', 'title']);
        $this->post(route('menu.store'), ['planned_on' => today()->toDateString(), 'meal' => 'snack', 'title' => 'X'])
            ->assertSessionHasErrors('meal');
        $this->post(route('menu.shop'), ['week' => today()->toDateString()])->assertSessionHasErrors('list_id');
        $this->assertSame(0, MealPlanEntry::count());
    }
}
