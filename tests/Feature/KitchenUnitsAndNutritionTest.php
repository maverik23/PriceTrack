<?php

namespace Tests\Feature;

use App\Enums\IngredientUnit;
use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\ShoppingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KitchenUnitsAndNutritionTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $data, ?float $price = null): Product
    {
        $product = Product::create($data);
        if ($price !== null) {
            $provider = Provider::firstOrCreate(['name' => 'Walmart']);
            Price::create(['product_id' => $product->id, 'provider_id' => $provider->id, 'price' => $price, 'pack_qty' => 1,
                'size' => $product->size, 'unit' => $product->unit, 'observed_on' => today()]);
        }

        return $product;
    }

    public function test_conversions_between_kitchen_units_and_products(): void
    {
        $arroz = $this->product(['name' => 'Arroz', 'size' => 1, 'unit' => 'kg', 'cup_grams' => 200]);
        $cebolla = $this->product(['name' => 'Cebolla', 'size' => 1, 'unit' => 'kg', 'piece_weight' => 150]);
        $aceite = $this->product(['name' => 'Aceite', 'size' => 946, 'unit' => 'ml']);
        $huevo = $this->product(['name' => 'Huevo', 'size' => 30, 'unit' => 'item', 'piece_weight' => 50]);

        $this->assertEqualsWithDelta(300, $arroz->fromIngredient(IngredientUnit::Cup, 1.5), 0.001);
        $this->assertEqualsWithDelta(450, $cebolla->fromIngredient(IngredientUnit::Item, 3), 0.001);
        $this->assertEqualsWithDelta(45, $aceite->fromIngredient(IngredientUnit::Tbsp, 3), 0.001);
        $this->assertEqualsWithDelta(10, $aceite->fromIngredient(IngredientUnit::Tsp, 2), 0.001);
        $this->assertEqualsWithDelta(4, $huevo->fromIngredient(IngredientUnit::G, 200), 0.001);
        $this->assertEqualsWithDelta(2, $huevo->fromIngredient(IngredientUnit::Item, 2), 0.001);

        // Sin el dato no se puede, y se dice cuál falta
        $this->assertNull($cebolla->fromIngredient(IngredientUnit::Cup, 1));
        $this->assertSame('cuánto pesa una taza', $cebolla->missingConversion(IngredientUnit::Cup));
        $this->assertSame('cuánto pesa una pieza', $arroz->missingConversion(IngredientUnit::Item));
        $this->assertNull($arroz->missingConversion(IngredientUnit::Kg));
    }

    public function test_recipe_with_kitchen_units_costs_shops_and_counts_macros(): void
    {
        $arroz = $this->product(['name' => 'Arroz 1 kg', 'size' => 1, 'unit' => 'kg', 'cup_grams' => 200,
            'kcal' => 360, 'protein' => 7, 'carbs' => 79, 'fat' => 0.6], 40);
        $huevo = $this->product(['name' => 'Huevo 30 pzas', 'size' => 30, 'unit' => 'item', 'piece_weight' => 50,
            'kcal' => 72, 'protein' => 6.3, 'carbs' => 0.4, 'fat' => 4.8], 90);
        $aceite = $this->product(['name' => 'Aceite 1 L', 'size' => 1, 'unit' => 'l', 'cup_grams' => 220], 50);

        $this->post(route('recipes.store'), ['name' => 'Arroz con huevo', 'servings' => 2, 'ingredients' => [
            ['product_id' => $arroz->id, 'quantity' => 1, 'unit' => 'cup'],
            ['product_id' => $huevo->id, 'quantity' => 4, 'unit' => 'item'],
            ['product_id' => $aceite->id, 'quantity' => 2, 'unit' => 'tbsp'],
            ['name' => 'Cebollín', 'quantity' => 1, 'unit' => 'tbsp'],
        ]])->assertSessionHasNoErrors();
        $recipe = Recipe::firstOrFail()->load('ingredients.product.prices');

        // 200 g de arroz ($8) + 4 huevos ($12) + 30 ml de aceite ($1.50)
        $this->assertEqualsWithDelta(21.5, $recipe->cost(), 0.001);

        $nutrition = $recipe->nutrition();
        // 720 + 288 kcal = 1,008 en total, 504 por porción
        $this->assertEqualsWithDelta(1008, $nutrition['total']['kcal'], 0.1);
        $this->assertEqualsWithDelta(504, $nutrition['perServing']['kcal'], 0.1);
        $this->assertEqualsWithDelta((14 + 25.2) / 2, $nutrition['perServing']['protein'], 0.1);
        $this->assertSame(1, $nutrition['missing'], 'el aceite no tiene nutrición');
        // 200 g + 200 g (4 × 50) + 27.5 g de aceite + 15 de cebollín ≈ 442.5 / 2
        $this->assertEqualsWithDelta(221, $recipe->gramsPerServing(), 1);

        $this->get(route('recipes.show', $recipe))->assertOk()->assertSee('1 taza')->assertSee('2 cda')
            ->assertSee('504')->assertSee('Por porción')->assertSee('unos 221 g en crudo');
        $this->get(route('recipes.show', [$recipe, 'servings' => 4]))->assertOk()->assertSee('2 tazas');
        $this->get(route('recipes.index'))->assertOk()->assertSee('504');

        $this->post(route('recipes.shop', $recipe))->assertSessionHasNoErrors();
        $items = ShoppingList::firstOrFail()->items->keyBy('product_id');
        $this->assertEqualsWithDelta(0.2, $items[$arroz->id]->quantity, 0.0001);
        $this->assertEqualsWithDelta(4 / 30, $items[$huevo->id]->quantity, 0.0001);
        $this->assertEqualsWithDelta(0.03, $items[$aceite->id]->quantity, 0.0001);
    }

    public function test_using_a_unit_without_the_product_data_asks_for_it(): void
    {
        $cebolla = $this->product(['name' => 'Cebolla', 'size' => 1, 'unit' => 'kg']);
        $this->post(route('recipes.store'), ['name' => 'Salsa', 'servings' => 4,
            'ingredients' => [['product_id' => $cebolla->id, 'quantity' => 1, 'unit' => 'item']]])
            ->assertSessionHasErrors(['ingredients.0.unit' => '«Cebolla» se mide en g, kg. Para usar pza indica en el artículo cuánto pesa una pieza.']);

        $this->put(route('products.update', $cebolla), ['name' => 'Cebolla', 'size' => 1, 'unit' => 'kg', 'piece_weight' => 150, 'kcal' => 40])
            ->assertSessionHasNoErrors();
        $this->post(route('recipes.store'), ['name' => 'Salsa', 'servings' => 4,
            'ingredients' => [['product_id' => $cebolla->id, 'quantity' => 1, 'unit' => 'item']]])->assertSessionHasNoErrors();

        // Si después se borra el peso de la pieza, el ingrediente deja de contar
        $cebolla->update(['piece_weight' => null]);
        $this->assertNull(Recipe::firstOrFail()->load('ingredients.product')->nutrition());
        $this->get(route('products.edit', $cebolla))->assertOk()->assertSee('Para recetas: peso y nutrición');
    }

    public function test_barcode_lookup_brings_nutrition_per_100_g(): void
    {
        Http::fake(['*openfoodfacts.org/*' => Http::response(['status' => 1, 'product' => [
            'product_name' => 'Avena', 'quantity' => '800 g',
            'nutriments' => ['energy-kcal_100g' => 375, 'proteins_100g' => 13.5, 'carbohydrates_100g' => 64, 'fat_100g' => 7],
        ]]), '*' => Http::response([], 404)]);

        $this->getJson(route('products.lookup', ['barcode' => '7501234567893']))->assertOk()
            ->assertJsonPath('suggestion.nutrition.kcal', 375)
            ->assertJsonPath('suggestion.nutrition.protein', 13.5)
            ->assertJsonPath('suggestion.nutrition.fat', 7);
    }
}
