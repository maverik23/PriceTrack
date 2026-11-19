<?php
namespace Tests\Feature;
use App\Models\{Price,Product,Provider,Recipe,ShoppingList,MealPlanEntry};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class RecipesAndMenuTest extends TestCase
{
    use RefreshDatabase;
    public function test_recipe_cost_menu_and_shopping_list_flow(): void
    {
        $w = Provider::create(['name' => 'Walmart']);
        $arroz = Product::create(['name' => 'Arroz 1 kg', 'size' => 1, 'unit' => 'kg']);
        Price::create(['product_id' => $arroz->id, 'provider_id' => $w->id, 'price' => 40, 'pack_qty' => 1, 'size' => 1, 'unit' => 'kg', 'observed_on' => today()]);
        $this->get(route('recipes.index'))->assertOk();
        $this->get(route('recipes.create'))->assertOk();
        $this->post(route('recipes.store'), ['name' => 'Arroz rojo', 'servings' => 4, 'category' => 'Guisados', 'instructions' => "Uno\nDos",
            'ingredients' => [['product_id' => $arroz->id, 'quantity' => 250, 'unit' => 'g'], ['name' => 'Sal', 'note' => 'al gusto'], ['name' => '']]])
            ->assertSessionHasNoErrors()->assertRedirect();
        $r = Recipe::firstOrFail();
        $this->assertCount(2, $r->ingredients);
        $this->assertEqualsWithDelta(10.0, $r->cost(), 0.001);
        $this->assertEqualsWithDelta(20.0, $r->cost(8), 0.001);
        $this->get(route('recipes.show', $r))->assertOk()->assertSee('Arroz rojo');
        $this->get(route('recipes.edit', $r))->assertOk();
        $this->get(route('recipes.index'))->assertOk()->assertSee('Arroz rojo');
        $this->post(route('recipes.store'), ['name' => 'X', 'servings' => 1, 'ingredients' => [['product_id' => $arroz->id, 'quantity' => 1, 'unit' => 'ml']]])->assertSessionHasErrors();
        $this->post(route('menu.store'), ['planned_on' => today()->toDateString(), 'meal' => 'lunch', 'recipe_id' => $r->id, 'servings' => 8])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('menu.store'), ['planned_on' => today()->toDateString(), 'meal' => 'dinner', 'title' => 'Sobras'])->assertSessionHasNoErrors();
        $this->get(route('menu.index'))->assertOk()->assertSee('Arroz rojo')->assertSee('Sobras');
        $this->post(route('menu.shop'), ['week' => today()->toDateString()])->assertSessionHasNoErrors()->assertRedirect();
        $list = ShoppingList::firstOrFail();
        $this->assertEqualsWithDelta(0.5, $list->items()->first()->quantity, 0.0001);
        $this->post(route('recipes.shop', $r), ['list_id' => $list->id])->assertRedirect(route('lists.show', $list));
        $this->assertEqualsWithDelta(0.75, $list->items()->first()->quantity, 0.0001);
        $this->get(route('lists.show', $list))->assertOk();
        $this->delete(route('menu.destroy', MealPlanEntry::first()))->assertRedirect();
    }
}
