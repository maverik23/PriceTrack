<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipePantryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipes_that_can_be_made_with_the_pantry(): void
    {
        $rice = Product::create(['name' => 'Arroz', 'size' => 1, 'unit' => 'kg']);
        $tomato = Product::create(['name' => 'Jitomate', 'size' => 1, 'unit' => 'kg']);
        $eggs = Product::create(['name' => 'Huevo', 'size' => 30, 'unit' => 'item']);
        $rice->setPantry(true);            // suficiente
        $tomato->setPantry(true, 100);     // solo quedan 100 g

        $plain = Recipe::create(['name' => 'Arroz blanco', 'servings' => 2]);
        $plain->ingredients()->create(['product_id' => $rice->id, 'name' => 'Arroz', 'quantity' => 200, 'unit' => 'g', 'position' => 0]);
        $plain->ingredients()->create(['name' => 'Sal', 'position' => 1]);

        $red = Recipe::create(['name' => 'Arroz rojo', 'servings' => 2]);
        $red->ingredients()->create(['product_id' => $rice->id, 'name' => 'Arroz', 'quantity' => 200, 'unit' => 'g', 'position' => 0]);
        $red->ingredients()->create(['product_id' => $tomato->id, 'name' => 'Jitomate', 'quantity' => 300, 'unit' => 'g', 'position' => 1]);

        // Sub-receta: el huevo no está en casa
        $withEgg = Recipe::create(['name' => 'Arroz con huevo', 'servings' => 2]);
        $withEgg->ingredients()->create(['subrecipe_id' => $plain->id, 'name' => 'Arroz blanco', 'quantity' => 2, 'unit' => 'serving', 'position' => 0]);
        $withEgg->ingredients()->create(['product_id' => $eggs->id, 'name' => 'Huevo', 'quantity' => 2, 'unit' => 'item', 'position' => 1]);

        $check = Recipe::withTree()->find($plain->id)->pantryCheck();
        $this->assertSame(['ready' => true, 'missing' => [], 'unchecked' => 1], $check);
        $this->assertSame(['Jitomate'], Recipe::withTree()->find($red->id)->pantryCheck()['missing']);
        $this->assertSame(['Huevo'], Recipe::withTree()->find($withEgg->id)->pantryCheck()['missing']);

        $this->get(route('recipes.index', ['pantry' => 1]))->assertOk()
            ->assertSee('Arroz blanco')->assertDontSee('Arroz rojo')->assertDontSee('Arroz con huevo')->assertSee('Tienes todo en casa');

        $this->get(route('recipes.index', ['pantry' => 'almost']))->assertOk()
            ->assertSee('Arroz rojo')->assertSee('Falta: Jitomate')->assertSee('Arroz con huevo')->assertDontSee('Tienes todo en casa');

        $this->get(route('recipes.index'))->assertOk()->assertSee('Arroz rojo')->assertSee('Con lo que hay');
    }
}
