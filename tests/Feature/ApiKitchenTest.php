<?php

namespace Tests\Feature;

use App\Models\MealDayNote;
use App\Models\MealPlanEntry;
use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\ShoppingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** API de la app móvil: Cocina (recetas, menú y envío a la lista). */
class ApiKitchenTest extends TestCase
{
    use RefreshDatabase;

    private Product $rice;

    private Product $salt;

    protected function setUp(): void
    {
        parent::setUp();

        $walmart = Provider::create(['name' => 'Walmart']);
        $this->rice = Product::create(['name' => 'Arroz', 'size' => 1, 'unit' => 'kg']);
        $this->salt = Product::create(['name' => 'Sal', 'size' => 1, 'unit' => 'kg', 'pantry' => Product::PANTRY_STOCKED]);
        foreach ([[$this->rice, 30], [$this->salt, 13]] as [$product, $price]) {
            Price::create(['product_id' => $product->id, 'provider_id' => $walmart->id, 'price' => $price, 'pack_qty' => 1, 'size' => 1, 'unit' => 'kg', 'observed_on' => today()]);
        }
    }

    private function createRecipe(array $extra = []): int
    {
        return $this->postJson('/api/v1/recipes', [
            'name' => 'Arroz rojo', 'servings' => 4, 'category' => 'Guarniciones', 'tags' => ['Rápidas'],
            'ingredients' => [
                ['product_id' => $this->rice->id, 'quantity' => 250, 'unit' => 'g', 'section' => 'Base'],
                ['product_id' => $this->salt->id, 'quantity' => 5, 'unit' => 'g', 'section' => 'Base'],
                ['name' => 'Agua', 'quantity' => 2, 'unit' => 'cup'],
            ],
            'steps' => [
                ['stage' => 'Sofrito', 'body' => 'Fríe el arroz.', 'minutes' => 5],
                ['stage' => 'Cocción', 'body' => 'Agrega el agua y tapa.', 'minutes' => 20],
            ],
            ...$extra,
        ])->assertCreated()->json('data.id');
    }

    public function test_recipe_crud_and_detail_at_factor_one(): void
    {
        $id = $this->createRecipe();

        $this->getJson("/api/v1/recipes/{$id}")->assertOk()
            ->assertJsonPath('data.name', 'Arroz rojo')
            ->assertJsonPath('data.servings', 4)
            // 250 g × $0.030 + 5 g × $0.013
            ->assertJsonPath('data.cost_exact', 7.565)
            ->assertJsonPath('data.sections.0.name', 'Base')
            ->assertJsonPath('data.sections.0.items.0.product_id', $this->rice->id)
            ->assertJsonPath('data.sections.0.items.0.status', 'ok')
            ->assertJsonPath('data.sections.1.items.0.kind', 'text')
            ->assertJsonPath('data.stages.1.name', 'Cocción')
            ->assertJsonPath('data.stages.1.steps.0.minutes', 20)
            ->assertJsonPath('data.tags', ['Rápidas'])
            ->assertJsonPath('data.pantry_check.missing', ['Arroz'])
            ->assertJsonPath('data.input.ingredients.0.quantity', 250)
            ->assertJsonMissingPath('data.sections.0.items.0.url');

        // Solo cambia lo que se manda: ingredientes y pasos se conservan
        $this->putJson("/api/v1/recipes/{$id}", ['servings' => 6, 'notes' => 'Con caldo sabe mejor'])->assertOk()
            ->assertJsonPath('data.servings', 6)
            ->assertJsonPath('data.notes', 'Con caldo sabe mejor')
            ->assertJsonCount(2, 'data.steps')
            ->assertJsonCount(2, 'data.sections');
        // Las reglas son las de la web
        $this->putJson("/api/v1/recipes/{$id}", ['ingredients' => [['product_id' => $this->rice->id, 'quantity' => 1, 'unit' => 'serving']]])
            ->assertStatus(422)->assertJsonValidationErrors('ingredients.0.unit');

        $this->postJson("/api/v1/recipes/{$id}/rating", ['rating' => 5])->assertOk()->assertJsonPath('rating', 5);
        $this->getJson('/api/v1/recipes/options')->assertOk()
            ->assertJsonPath('units.cup.base', 240)
            ->assertJsonPath('categories', ['Guarniciones'])
            ->assertJsonPath('meals.snack', 'Merienda');
        // Una receta no se ofrece como sub-receta de sí misma
        $this->assertSame([], $this->getJson("/api/v1/recipes/options?recipe_id={$id}")->json('recipes'));

        $this->deleteJson("/api/v1/recipes/{$id}")->assertOk();
        $this->assertNull(Recipe::find($id));
    }

    public function test_recipe_index_filters_pantry_and_detail(): void
    {
        $this->createRecipe();
        $this->createRecipe(['name' => 'Agua salada', 'ingredients' => [['product_id' => $this->salt->id, 'quantity' => 5, 'unit' => 'g']], 'tags' => []]);

        $this->getJson('/api/v1/recipes')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('counts.ready', 1)
            ->assertJsonPath('data.1.pantry_check.missing', ['Arroz']);
        $this->getJson('/api/v1/recipes?pantry=ready')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Agua salada');
        $this->getJson('/api/v1/recipes?tag=R%C3%A1pidas')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Arroz rojo');
        $this->getJson('/api/v1/recipes?q=arroz&detail=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonStructure(['data' => [['sections', 'stages', 'input']]]);
        $this->getJson('/api/v1/recipes?per_page=1&page=2')->assertOk()->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.name', 'Arroz rojo');
    }

    public function test_recipe_photos(): void
    {
        Storage::fake('local');
        $id = $this->createRecipe();

        $this->post("/api/v1/recipes/{$id}/attachments", ['files' => [UploadedFile::fake()->image('arroz.jpg')]])
            ->assertOk()->assertJsonCount(1, 'data.attachments')->assertJsonPath('data.photo.url', fn ($url) => str_starts_with($url, 'attachments/'));
    }

    public function test_shop_review_and_send_to_a_list(): void
    {
        $id = $this->createRecipe();

        $review = $this->getJson("/api/v1/recipes/{$id}/shop?servings=8")->assertOk()->json('data');
        $this->assertSame(8, $review['servings']);
        $this->assertSame(['Agua'], $review['unlinked']);
        $rice = collect($review['rows'])->firstWhere('id', $this->rice->id);
        $this->assertTrue($rice['checked']);
        $this->assertSame('500 g', $rice['need']);
        // La sal está en casa: viene desmarcada
        $this->assertFalse(collect($review['rows'])->firstWhere('id', $this->salt->id)['checked']);
        $this->assertArrayNotHasKey('url', $rice);

        $sent = $this->postJson("/api/v1/recipes/{$id}/shop", ['servings' => 8])->assertOk()
            ->assertJsonPath('added', 1)->assertJsonPath('skipped', ['Sal'])->json();
        $list = ShoppingList::findOrFail($sent['list']['id']);
        $this->assertSame('Arroz rojo', $list->name);
        $this->assertEqualsWithDelta(0.5, $list->items()->first()->quantity, 0.0001);

        // Con revisión: se marca la sal (se acabó) y se agrega a la misma lista
        $this->postJson("/api/v1/recipes/{$id}/shop", ['list_id' => $list->id, 'reviewed' => 1, 'buy' => [$this->salt->id]])
            ->assertOk()->assertJsonPath('added', 1)->assertJsonPath('list.id', $list->id);
        $this->assertSame(Product::PANTRY_OUT, $this->salt->fresh()->pantry);
        $this->assertSame(Product::PANTRY_STOCKED, $this->rice->fresh()->pantry);

        // Sin artículos ligados no hay nada que mandar
        $plain = $this->createRecipe(['name' => 'Agua', 'ingredients' => [['name' => 'Agua']]]);
        $this->postJson("/api/v1/recipes/{$plain}/shop")->assertStatus(422)->assertJsonValidationErrors('list_id');
    }

    public function test_weekly_menu(): void
    {
        $id = $this->createRecipe();
        $monday = today()->startOfWeek()->toDateString();
        $wednesday = today()->startOfWeek()->addDays(2)->toDateString();

        $entry = $this->postJson('/api/v1/menu', ['planned_on' => $wednesday, 'meal' => 'lunch', 'recipe_id' => $id, 'servings' => 2])
            ->assertCreated()->assertJsonPath('data.week_start', $monday)->json('id');
        $this->postJson('/api/v1/menu', ['planned_on' => $wednesday, 'meal' => 'dinner', 'title' => 'Sobras'])->assertCreated();
        $this->postJson('/api/v1/menu', ['planned_on' => $wednesday, 'meal' => 'dinner'])->assertStatus(422)->assertJsonValidationErrors(['recipe_id', 'title']);
        $this->putJson("/api/v1/menu/notes/{$wednesday}", ['body' => 'Mealprep: cocer arroz'])->assertOk()->assertJsonPath('body', 'Mealprep: cocer arroz');

        $week = $this->getJson("/api/v1/menu?week={$wednesday}")->assertOk()->json('data');
        $this->assertSame($monday, $week['week_start']);
        $day = $week['days'][2];
        $this->assertSame('Mealprep: cocer arroz', $day['note']);
        $this->assertSame(2, $day['count']);
        $lunch = collect($day['meals'])->firstWhere('meal', 'lunch');
        $this->assertSame('Arroz rojo', $lunch['items'][0]['label']);
        $this->assertSame(2, $lunch['items'][0]['servings']);
        // Costo de 2 porciones de 4: la mitad de $7.565
        $this->assertEqualsWithDelta(3.78, $lunch['cost'], 0.01);
        $this->assertSame('Sobras', collect($day['meals'])->firstWhere('meal', 'dinner')['items'][0]['label']);
        $this->assertEqualsWithDelta(3.78, $week['total'], 0.01);

        // Mandar la semana a una lista
        $this->getJson("/api/v1/menu/shop?week={$wednesday}")->assertOk()->assertJsonPath('data.week', $monday)
            ->assertJsonPath('data.new_list', fn ($name) => str_starts_with($name, 'Menú del '));
        $this->postJson('/api/v1/menu/shop', ['week' => $wednesday])->assertOk()->assertJsonPath('added', 1);

        $this->deleteJson("/api/v1/menu/{$entry}")->assertOk()->assertJsonPath('data.days.2.count', 1);
        $this->assertSame(1, MealPlanEntry::count());
        $this->assertSame(1, MealDayNote::count());
    }

    public function test_product_becomes_a_subrecipe(): void
    {
        $sauce = Product::create(['name' => 'Salsa casera', 'size' => 500, 'unit' => 'g']);
        $user = $this->createRecipe(['name' => 'Enchiladas', 'ingredients' => [['product_id' => $sauce->id, 'quantity' => 250, 'unit' => 'g']]]);
        $sauceRecipe = $this->createRecipe(['name' => 'Salsa', 'yield_qty' => 500, 'yield_unit' => 'g', 'ingredients' => [['name' => 'Chile']]]);

        $this->postJson("/api/v1/products/{$sauce->id}/subrecipe", ['recipe_id' => $sauceRecipe])->assertOk()
            ->assertJsonPath('converted', ['Enchiladas'])->assertJsonStructure(['message', 'skipped']);
        $this->assertSame($sauceRecipe, Recipe::find($user)->ingredients()->first()->subrecipe_id);
    }
}
