<?php

namespace Tests\Feature;

use App\Models\MealPlanEntry;
use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_shows_cost_and_macros_per_meal_and_per_day(): void
    {
        $provider = Provider::create(['name' => 'Súper']);
        $rice = Product::create(['name' => 'Arroz', 'size' => 1, 'unit' => 'kg', 'kcal' => 360, 'protein' => 7, 'carbs' => 79, 'fat' => 1]);
        Price::create(['product_id' => $rice->id, 'provider_id' => $provider->id, 'price' => 40, 'pack_qty' => 1, 'size' => 1, 'unit' => 'kg', 'observed_on' => today()]);

        // 400 g de arroz para 4 porciones: $16; 100 g por porción = 360 kcal
        $recipe = Recipe::create(['name' => 'Arroz blanco', 'servings' => 4]);
        $recipe->ingredients()->create(['product_id' => $rice->id, 'name' => 'Arroz', 'quantity' => 400, 'unit' => 'g', 'position' => 0]);

        $monday = today()->startOfWeek();
        MealPlanEntry::create(['planned_on' => $monday, 'meal' => 'lunch', 'recipe_id' => $recipe->id]);
        MealPlanEntry::create(['planned_on' => $monday, 'meal' => 'dinner', 'recipe_id' => $recipe->id, 'servings' => 2]);
        MealPlanEntry::create(['planned_on' => $monday, 'meal' => 'snack', 'title' => 'Fruta']);

        $response = $this->get(route('menu.index', ['week' => $monday->toDateString()]))->assertOk();
        $day = $response->viewData('days')->first();

        $this->assertEqualsWithDelta(16, $day['meals']['lunch']['cost'], 0.001);
        $this->assertEqualsWithDelta(8, $day['meals']['dinner']['cost'], 0.001);
        $this->assertEqualsWithDelta(360, $day['meals']['dinner']['macros']['kcal'], 0.1, 'Macros de una porción');
        // Día: $24 y dos porciones de arroz (720 kcal); la fruta no tiene datos: aproximado
        $this->assertEqualsWithDelta(24, $day['cost'], 0.001);
        $this->assertEqualsWithDelta(720, $day['macros']['kcal'], 0.1);
        $this->assertTrue($day['partial']);

        $response->assertSee('Total del día')->assertSee('$24.00')->assertSee('~720 kcal')->assertSee('Fruta');
        $this->assertEqualsWithDelta(24, $response->viewData('total'), 0.001);
    }

    public function test_the_plus_of_each_meal_is_the_only_way_to_add(): void
    {
        $recipe = Recipe::create(['name' => 'Arroz blanco', 'servings' => 4]);
        $monday = today()->startOfWeek();

        // Un solo formulario (se mueve a la comida cuyo «+» se toca) y un lugar para él en cada comida
        $html = $this->get(route('menu.index', ['week' => $monday->toDateString()]))->assertOk()
            ->assertDontSee('Agregar al menú</h2>', false)
            ->assertSee('id="add-'.$monday->toDateString().'|snack"', false)
            ->assertSee('data-servings="4"', false)
            ->getContent();
        $this->assertSame(1, substr_count($html, 'action="'.route('menu.store').'"'));

        // Si el alta falla, se reabre el mismo «+» con lo escrito
        $this->from(route('menu.index'))->post(route('menu.store'), ['planned_on' => $monday->toDateString(), 'meal' => 'snack', 'recipe_id' => $recipe->id, 'servings' => 0])
            ->assertSessionHasErrors('servings');
        $this->get(route('menu.index'))->assertSee($monday->toDateString().'|snack', false);
    }
}
