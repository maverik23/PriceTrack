<?php

namespace Tests\Feature;

use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Recipe;
use App\Support\RecipeData;
use App\Support\RecipeView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeScreenTest extends TestCase
{
    use RefreshDatabase;

    private Recipe $recipe;

    protected function setUp(): void
    {
        parent::setUp();

        $walmart = Provider::create(['name' => 'Walmart']);
        $sal = Product::create(['name' => 'Sal', 'size' => 1, 'unit' => 'kg']);
        // $0.013 por g: los centavos no se pierden al multiplicar en la pantalla
        Price::create(['product_id' => $sal->id, 'provider_id' => $walmart->id, 'price' => 13, 'pack_qty' => 1, 'size' => 1, 'unit' => 'kg', 'observed_on' => today()]);

        $this->recipe = RecipeData::save(new Recipe, RecipeData::validate([
            'name' => 'Caldo', 'servings' => 4, 'rating' => 4, 'tags' => ['Rápidas'],
            'ingredients' => [
                ['product_id' => $sal->id, 'quantity' => 3, 'unit' => 'g', 'section' => 'Base'],
                ['name' => 'Agua', 'quantity' => 1.5, 'unit' => 'cup'],
            ],
            'steps' => [['body' => 'Hierve el agua.', 'minutes' => 10, 'stage' => 'Caldo'], ['body' => 'Sala y sirve.']],
        ]));
    }

    public function test_screen_sends_base_values_and_renders_the_chosen_servings(): void
    {
        $data = RecipeView::data($this->recipe->loadShoppingTree()->load('steps'));
        $this->assertSame(4, $data['servings']);
        // Sin redondear: 3 g × $0.013 = $0.039
        $this->assertEqualsWithDelta(0.039, $data['cost'], 0.000001);
        $this->assertSame('Base', $data['sections'][0]['name']);
        $this->assertSame(['label' => 'taza', 'plural' => 'tazas'], $data['units']['cup']);
        $this->assertSame([10, null], array_column($data['steps'], 'minutes'));

        $this->get(route('recipes.show', [$this->recipe, 'servings' => 8]))->assertOk()
            ->assertSee('6 g')->assertSee('3 tazas')->assertSee('Agregar (8 porciones)')
            ->assertSee('"initialServings":8', false)
            ->assertSee('Modo cocinar')->assertSee('Hierve el agua.')
            ->assertSee('Rápidas');

        // Sin pasos no hay modo cocinar
        $this->recipe->steps()->delete();
        $this->get(route('recipes.show', $this->recipe))->assertOk()->assertDontSee('aria-label="Modo cocinar', false);
    }

    /** El contrato que replica quantityLabel() de resources/js/recipe-show.js. */
    public function test_quantity_labels(): void
    {
        $this->assertSame('1 taza', RecipeView::quantityLabel(0.5, 'cup', 2));
        $this->assertSame('1.5 tazas', RecipeView::quantityLabel(1.5, 'cup'));
        $this->assertSame('1 porción', RecipeView::quantityLabel(2, 'serving', 0.5));
        $this->assertSame('3 porciones', RecipeView::quantityLabel(3, 'serving'));
        $this->assertSame('1,234.568 ml', RecipeView::quantityLabel(1234.5678, 'ml'));
        $this->assertSame('1 kg', RecipeView::quantityLabel(0.3333, 'kg', 3));
        $this->assertSame('5', RecipeView::quantityLabel(5, null));
        $this->assertSame('', RecipeView::quantityLabel(null, 'g'));
    }

    public function test_recipe_cards_in_the_index(): void
    {
        $this->get(route('recipes.index'))->assertOk()->assertSee('Caldo')->assertSee('Rápidas')->assertSee('4 de 5');
        $this->get(route('recipes.index', ['q' => 'nada']))->assertOk()->assertSee('Ninguna receta coincide');
    }
}
