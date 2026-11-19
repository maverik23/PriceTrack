<?php

namespace Tests\Feature;

use App\Mcp\PriceTrackServer;
use App\Mcp\Tools\DeleteRecord;
use App\Mcp\Tools\GetPantry;
use App\Mcp\Tools\ListRecipes;
use App\Mcp\Tools\SaveProductLink;
use App\Mcp\Tools\SaveProvider;
use App\Mcp\Tools\UpdateListItem;
use App\Mcp\Tools\UpdatePantry;
use App\Mcp\Tools\UpdateProduct;
use App\Mcp\Tools\UpdateShoppingList;
use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class McpSuperAndKitchenTest extends TestCase
{
    use RefreshDatabase;

    private Provider $store;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 12:00:00');
        $this->store = Provider::create(['name' => 'Tienda']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, float $size, string $unit, ?float $price = null): Product
    {
        $product = Product::create(['name' => $name, 'size' => $size, 'unit' => $unit]);
        if ($price !== null) {
            Price::create(['product_id' => $product->id, 'provider_id' => $this->store->id, 'price' => $price, 'pack_qty' => 1, 'size' => $size, 'unit' => $unit, 'observed_on' => today()]);
        }

        return $product;
    }

    public function test_products_providers_and_store_links_are_updated(): void
    {
        $milk = $this->product('Leche', 1, 'l', 28);
        Product::create(['name' => 'Yogur', 'size' => 1, 'unit' => 'kg', 'brand' => 'Lala']);

        PriceTrackServer::tool(UpdateProduct::class, ['product_id' => $milk->id, 'brand' => 'lala', 'kcal' => 60])->assertOk();
        $this->assertSame(['Lala', 60.0, 'Leche'], [$milk->fresh()->brand, (float) $milk->fresh()->kcal, $milk->fresh()->name]);
        // Con precios en litros no se puede pasar a kilos
        PriceTrackServer::tool(UpdateProduct::class, ['product_id' => $milk->id, 'unit' => 'kg'])->assertHasErrors();

        PriceTrackServer::tool(SaveProvider::class, ['name' => 'Chedraui Universidad'])->assertOk();
        $chedraui = Provider::where('name', 'Chedraui Universidad')->firstOrFail();
        $this->assertSame('chedraui', $chedraui->chain);
        PriceTrackServer::tool(SaveProvider::class, ['id' => $this->store->id, 'chain' => 'walmart', 'branch' => '2344', 'branch_name' => 'Walmart Coapa · 2 km'])->assertOk();
        $this->assertSame(['walmart', '2344', 'Walmart Coapa'], [$this->store->fresh()->chain, $this->store->fresh()->branch, $this->store->fresh()->branch_name]);
        PriceTrackServer::tool(SaveProvider::class, ['id' => $this->store->id, 'branch' => 'abc'])->assertHasErrors();

        PriceTrackServer::tool(SaveProductLink::class, ['product_id' => $milk->id, 'provider_id' => $this->store->id, 'url' => 'https://www.walmart.com.mx/ip/leche/123', 'brand' => 'lala'])->assertOk();
        $link = $milk->links()->firstOrFail();
        $this->assertSame('Lala', $link->brand);
        // Solo cambia lo que se manda
        PriceTrackServer::tool(SaveProductLink::class, ['product_id' => $milk->id, 'provider_id' => $this->store->id, 'barcode' => '7501020515343'])->assertOk();
        $this->assertSame(['https://www.walmart.com.mx/ip/leche/123', 'Lala'], [$link->fresh()->url, $link->fresh()->brand]);
        PriceTrackServer::tool(SaveProductLink::class, ['product_id' => $milk->id, 'provider_id' => $this->store->id, 'url' => 'http://x.com'])->assertHasErrors();
        PriceTrackServer::tool(SaveProductLink::class, ['product_id' => $milk->id, 'provider_id' => $this->store->id, 'url' => null, 'brand' => null, 'barcode' => null])->assertOk();
        $this->assertSame(0, $milk->links()->count());
    }

    public function test_lists_get_their_ticket_bought_quantity_and_can_be_closed(): void
    {
        $water = $this->product('Agua', 1, 'l', 10);
        $list = ShoppingList::create(['name' => 'Súper', 'shopped_on' => today()]);
        $item = $list->items()->create(['product_id' => $water->id, 'quantity' => 2]);

        PriceTrackServer::tool(UpdateListItem::class, ['item_id' => $item->id, 'bought_qty' => 3])->assertHasErrors(['Marca antes']);
        PriceTrackServer::tool(UpdateListItem::class, ['item_id' => $item->id, 'purchased_at_provider_id' => $this->store->id, 'bought_qty' => 3])->assertOk();
        $this->assertSame(30.0, $item->fresh()->subtotal());

        PriceTrackServer::tool(UpdateShoppingList::class, ['list_id' => $list->id, 'name' => 'Súper del jueves', 'ticket_provider_id' => $this->store->id, 'ticket_total' => 27.5])->assertOk()->assertSee('27.5');
        $this->assertSame([27.5, 'Súper del jueves'], [(float) $list->fresh()->total(), $list->fresh()->name]);
        PriceTrackServer::tool(UpdateShoppingList::class, ['list_id' => $list->id, 'ticket_provider_id' => $this->store->id, 'ticket_total' => null])->assertOk();
        $this->assertSame(0, $list->tickets()->count());

        PriceTrackServer::tool(UpdateShoppingList::class, ['list_id' => $list->id, 'done' => false])->assertOk();
        $this->assertFalse($list->fresh()->isDone());
        PriceTrackServer::tool(UpdateShoppingList::class, ['list_id' => $list->id, 'done' => true])->assertOk();
        $this->assertTrue($list->fresh()->isDone());
    }

    public function test_pantry_value_lists_and_recipes_that_can_be_made(): void
    {
        $rice = $this->product('Arroz', 1, 'kg', 30);
        $beans = $this->product('Frijol', 1, 'kg', 40);
        $rice->setPantry(true, 500);
        $beans->setPantry(true, null);

        $recipe = Recipe::create(['name' => 'Arroz con frijoles', 'servings' => 2]);
        RecipeIngredient::create(['recipe_id' => $recipe->id, 'product_id' => $rice->id, 'name' => 'Arroz', 'quantity' => 200, 'unit' => 'g', 'position' => 0]);
        RecipeIngredient::create(['recipe_id' => $recipe->id, 'product_id' => $beans->id, 'name' => 'Frijol', 'quantity' => 200, 'unit' => 'g', 'position' => 1]);
        $other = Recipe::create(['name' => 'Arroz grande', 'servings' => 2]);
        RecipeIngredient::create(['recipe_id' => $other->id, 'product_id' => $rice->id, 'name' => 'Arroz', 'quantity' => 900, 'unit' => 'g', 'position' => 0]);

        PriceTrackServer::tool(GetPantry::class)->assertOk()->assertSee('total_value')->assertSee('55');
        PriceTrackServer::tool(ListRecipes::class, ['pantry' => 'ready'])->assertOk()->assertSee('Arroz con frijoles')->assertDontSee('Arroz grande');
        PriceTrackServer::tool(ListRecipes::class, ['pantry' => 'almost'])->assertOk()->assertSee('Arroz grande')->assertDontSee('Arroz con frijoles');

        // Se acabó el arroz: a una lista nueva, sin duplicar
        PriceTrackServer::tool(UpdatePantry::class, ['items' => [['product_id' => $rice->id, 'have' => false]], 'add_to_list' => true])->assertOk();
        $list = ShoppingList::firstOrFail();
        $this->assertSame([$rice->id], $list->items()->pluck('product_id')->all());
        PriceTrackServer::tool(UpdatePantry::class, ['items' => [['product_id' => $rice->id, 'have' => false]], 'list_id' => $list->id])->assertOk();
        $this->assertSame(1, $list->items()->count());
        PriceTrackServer::tool(GetPantry::class)->assertOk()->assertSee($list->name);
    }

    public function test_prices_lists_products_and_recipes_are_deleted(): void
    {
        $milk = $this->product('Leche', 1, 'l', 28);
        $sauce = Recipe::create(['name' => 'Salsa', 'servings' => 4]);
        $tacos = Recipe::create(['name' => 'Tacos', 'servings' => 4]);
        RecipeIngredient::create(['recipe_id' => $tacos->id, 'subrecipe_id' => $sauce->id, 'name' => 'Salsa', 'quantity' => 1, 'unit' => 'serving', 'position' => 0]);
        $list = ShoppingList::create(['name' => 'Vieja', 'shopped_on' => today()]);

        PriceTrackServer::tool(DeleteRecord::class, ['type' => 'price', 'id' => $milk->prices()->first()->id])->assertOk();
        $this->assertSame(0, $milk->prices()->count());
        PriceTrackServer::tool(DeleteRecord::class, ['type' => 'recipe', 'id' => $sauce->id])->assertOk()->assertSee('Tacos');
        $this->assertNull($tacos->ingredients()->first()->subrecipe_id);
        PriceTrackServer::tool(DeleteRecord::class, ['type' => 'shopping_list', 'id' => $list->id])->assertOk();
        PriceTrackServer::tool(DeleteRecord::class, ['type' => 'product', 'id' => $milk->id])->assertOk();
        $this->assertSame([0, 0], [ShoppingList::count(), Product::count()]);
        PriceTrackServer::tool(DeleteRecord::class, ['type' => 'product', 'id' => $milk->id])->assertHasErrors();
    }
}
