<?php

namespace Tests\Feature;

use App\Mcp\PriceTrackServer;
use App\Mcp\Tools\AddIngredientsToList;
use App\Mcp\Tools\GetPantry;
use App\Mcp\Tools\UpdatePantry;
use App\Models\MealPlanEntry;
use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\ShoppingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PantryTest extends TestCase
{
    use RefreshDatabase;

    private Provider $walmart;

    private Product $arroz;

    private Product $aceite;

    private Product $comino;

    private Recipe $recipe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->walmart = Provider::create(['name' => 'Walmart']);
        $this->arroz = $this->product('Arroz', 1, 'kg', 40);
        $this->aceite = $this->product('Aceite', 1, 'l', 60);
        $this->comino = $this->product('Comino', 50, 'g', 25);

        $this->post(route('recipes.store'), [
            'name' => 'Arroz rojo', 'servings' => 4,
            'ingredients' => [
                ['product_id' => $this->arroz->id, 'quantity' => 500, 'unit' => 'g'],
                ['product_id' => $this->aceite->id, 'quantity' => 2, 'unit' => 'tbsp'],
                ['product_id' => $this->comino->id, 'quantity' => 1, 'unit' => 'g'],
                ['name' => 'Sal'],
            ],
        ])->assertSessionHasNoErrors();
        $this->recipe = Recipe::latest('id')->firstOrFail();
    }

    private function product(string $name, float $size, string $unit, float $price): Product
    {
        $product = Product::create(['name' => $name, 'size' => $size, 'unit' => $unit]);
        Price::create([
            'product_id' => $product->id, 'provider_id' => $this->walmart->id, 'price' => $price,
            'pack_qty' => 1, 'size' => $size, 'unit' => $unit, 'observed_on' => today(),
        ]);

        return $product;
    }

    public function test_pantry_screen_and_toggle(): void
    {
        $this->get(route('pantry.index'))->assertOk()->assertSee('Despensa')->assertSee('Comino');

        $this->postJson(route('products.pantry', $this->comino), ['have' => true])
            ->assertOk()->assertJsonPath('row.pantry', 'stocked')->assertJsonPath('row.qty', null);
        $this->assertTrue($this->comino->fresh()->inPantry());
        $this->assertNotNull($this->comino->fresh()->pantry_updated_at);

        // Cuánto queda, con unidad o sin ella (en la unidad del artículo)
        $this->postJson(route('products.pantry', $this->arroz), ['have' => true, 'qty' => '300 g'])->assertJsonPath('row.qty', '300 g');
        $this->assertEqualsWithDelta(300, $this->arroz->fresh()->pantry_qty, 0.001);
        $this->postJson(route('products.pantry', $this->arroz), ['have' => true, 'qty' => '1.5'])->assertJsonPath('row.qty', '1.5 kg');
        $this->postJson(route('products.pantry', $this->arroz), ['have' => true, 'qty' => '2 L'])->assertJsonValidationErrors('qty');

        // Desmarcar uno que se llevaba en casa = "se acabó"
        $this->postJson(route('products.pantry', $this->comino), ['have' => false])->assertJsonPath('row.pantry', 'out');
        $this->postJson(route('products.pantry', $this->comino), ['have' => false, 'forget' => true])->assertJsonPath('row.pantry', null);

        // Sin JS (formulario normal) también funciona
        $this->post(route('products.pantry', $this->aceite), ['have' => 1])->assertRedirect()->assertSessionHas('success');
        $this->assertTrue($this->aceite->fresh()->inPantry());

        $this->get(route('products.show', $this->aceite))->assertOk()->assertSee('Lo tengo en casa');
    }

    public function test_pantry_is_not_sent_to_the_list_and_partial_sends_what_is_missing(): void
    {
        $this->aceite->setPantry(true);
        $this->arroz->setPantry(true, 200);

        // Sin revisión: se manda lo que falta
        $this->post(route('recipes.shop', $this->recipe))->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Ya tienes en casa: Aceite') && str_contains($m, 'Sal'));

        $list = ShoppingList::firstOrFail();
        $items = $list->items()->get()->keyBy('product_id');
        $this->assertFalse($items->has($this->aceite->id));
        $this->assertEqualsWithDelta(0.3, $items[$this->arroz->id]->quantity, 0.0001);
        $this->assertEqualsWithDelta(0.02, $items[$this->comino->id]->quantity, 0.0001);
    }

    public function test_review_screen_and_corrections_update_the_pantry(): void
    {
        $this->aceite->setPantry(true);

        $this->get(route('recipes.shop.review', [$this->recipe, 'servings' => 8]))->assertOk()
            ->assertSee('¿Qué hay que comprar?')->assertSee('Aceite')->assertSee('1 kg')->assertSee('Sal');

        // Corregir: el aceite se acabó (se marca) y el comino ya lo tengo (se desmarca)
        $this->post(route('recipes.shop', $this->recipe), [
            'reviewed' => 1, 'servings' => 4, 'buy' => [$this->arroz->id, $this->aceite->id],
        ])->assertRedirect();

        $items = ShoppingList::firstOrFail()->items()->get()->keyBy('product_id');
        $this->assertEqualsWithDelta(0.5, $items[$this->arroz->id]->quantity, 0.0001);
        $this->assertEqualsWithDelta(0.03, $items[$this->aceite->id]->quantity, 0.0001);
        $this->assertFalse($items->has($this->comino->id));
        $this->assertSame(Product::PANTRY_OUT, $this->aceite->fresh()->pantry);
        $this->assertTrue($this->comino->fresh()->inPantry());

        // Nada marcado: solo actualiza la despensa, no crea lista
        $this->post(route('recipes.shop', $this->recipe), ['reviewed' => 1])->assertRedirect(route('recipes.show', $this->recipe));
        $this->assertSame(1, ShoppingList::count());
        $this->assertTrue($this->arroz->fresh()->inPantry());
    }

    public function test_buying_a_staple_that_ran_out_marks_it_in_pantry(): void
    {
        $this->aceite->update(['pantry' => Product::PANTRY_OUT]);
        $list = ShoppingList::create(['name' => 'Súper', 'shopped_on' => today()]);
        $item = $list->items()->create(['product_id' => $this->aceite->id, 'quantity' => 1]);
        $fresh = $list->items()->create(['product_id' => $this->arroz->id, 'quantity' => 1]);

        $this->patchJson(route('lists.items.update', [$list, $item]), ['price_id' => $this->aceite->prices()->first()->id])->assertOk();
        $this->patchJson(route('lists.items.update', [$list, $fresh]), ['price_id' => $this->arroz->prices()->first()->id])->assertOk();

        $this->assertTrue($this->aceite->fresh()->inPantry());
        // Lo que no se lleva en la despensa (verdura, carne) no se marca solo
        $this->assertNull($this->arroz->fresh()->pantry);
    }

    public function test_menu_review_skips_pantry(): void
    {
        $this->comino->setPantry(true);
        MealPlanEntry::create(['planned_on' => today(), 'meal' => 'lunch', 'recipe_id' => $this->recipe->id]);

        $this->get(route('menu.shop.review', ['week' => today()->toDateString()]))->assertOk()->assertSee('Comino')->assertSee('"have":"suficiente"', false);
        $this->post(route('menu.shop'), ['week' => today()->toDateString()])->assertRedirect();

        $items = ShoppingList::firstOrFail()->items()->pluck('product_id');
        $this->assertNotContains($this->comino->id, $items);
        $this->assertContains($this->arroz->id, $items);
    }

    public function test_mcp_pantry_tools(): void
    {
        PriceTrackServer::tool(UpdatePantry::class, ['items' => [
            ['product_id' => $this->aceite->id, 'have' => true],
            ['product_id' => $this->arroz->id, 'have' => true, 'remaining' => '400 g'],
        ]])->assertOk()->assertSee('400 g');
        PriceTrackServer::tool(UpdatePantry::class, ['items' => [['product_id' => $this->arroz->id, 'have' => true, 'remaining' => 'mucho']]])
            ->assertHasErrors(['No entendí la cantidad']);

        PriceTrackServer::tool(GetPantry::class)->assertOk()->assertSee('Aceite')->assertSee('400 g');

        PriceTrackServer::tool(AddIngredientsToList::class, ['recipe_id' => $this->recipe->id, 'preview' => true])
            ->assertOk()->assertSee('"preview":true')->assertSee('suficiente')->assertSee('100 g');
        $this->assertSame(0, ShoppingList::count());

        PriceTrackServer::tool(AddIngredientsToList::class, ['recipe_id' => $this->recipe->id])
            ->assertOk()->assertSee('already_have')->assertSee('Aceite');
        $items = ShoppingList::firstOrFail()->items()->get()->keyBy('product_id');
        $this->assertFalse($items->has($this->aceite->id));
        $this->assertEqualsWithDelta(0.1, $items[$this->arroz->id]->quantity, 0.0001);
    }
}
