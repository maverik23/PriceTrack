<?php

namespace Tests\Feature;

use App\Mcp\PriceTrackServer;
use App\Mcp\Tools\AddIngredientsToList;
use App\Mcp\Tools\AddListItem;
use App\Mcp\Tools\CompleteTask;
use App\Mcp\Tools\CreateProduct;
use App\Mcp\Tools\CreateRecipe;
use App\Mcp\Tools\CreateTask;
use App\Mcp\Tools\GetFinances;
use App\Mcp\Tools\GetProduct;
use App\Mcp\Tools\GetShoppingList;
use App\Mcp\Tools\GetSummary;
use App\Mcp\Tools\MarkPaymentPaid;
use App\Mcp\Tools\PlanMeal;
use App\Mcp\Tools\RecordPrice;
use App\Mcp\Tools\SearchProducts;
use App\Mcp\Tools\UpdateListItem;
use App\Mcp\Tools\UpdateTask;
use App\Models\MealPlanEntry;
use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\RecurringPayment;
use App\Models\ShoppingList;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private Provider $walmart;

    private Provider $sams;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-19 10:00'));
        $this->walmart = Provider::create(['name' => 'Walmart']);
        $this->sams = Provider::create(['name' => "Sam's Club"]);
    }

    private function product(string $name, float $size, string $unit, array $prices = []): Product
    {
        $product = Product::create(['name' => $name, 'size' => $size, 'unit' => $unit]);
        foreach ($prices as [$provider, $price, $packSize]) {
            Price::create([
                'product_id' => $product->id, 'provider_id' => $provider->id, 'price' => $price,
                'pack_qty' => 1, 'size' => $packSize, 'unit' => $unit, 'observed_on' => today(),
            ]);
        }

        return $product;
    }

    private function rpc(string $method, array $params = [], array $headers = [])
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], $headers);
    }

    public function test_the_http_endpoint_lists_every_tool_in_one_page(): void
    {
        $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']])
            ->assertOk()->assertJsonPath('result.serverInfo.name', 'PriceTrack');

        $tools = $this->rpc('tools/list')->assertOk()->assertJsonMissingPath('result.nextCursor')->json('result.tools');

        $this->assertCount(38, $tools);
        $this->assertContains('search-products', array_column($tools, 'name'));
        $this->assertTrue(collect($tools)->firstWhere('name', 'get-summary')['annotations']['readOnlyHint']);
    }

    public function test_a_tool_can_be_called_over_http(): void
    {
        $this->product('Leche entera', 1, 'l', [[$this->walmart, 30, 1]]);

        $text = $this->rpc('tools/call', ['name' => 'search-products', 'arguments' => ['query' => 'leche']])
            ->assertOk()->assertJsonPath('result.isError', false)->json('result.content.0.text');

        $this->assertSame('Leche entera', json_decode($text, true)['products'][0]['name']);
    }

    public function test_the_http_endpoint_requires_the_token_when_one_is_set(): void
    {
        config(['services.mcp.token' => 'secreto']);

        $this->rpc('tools/list')->assertUnauthorized();
        $this->rpc('tools/list', [], ['Authorization' => 'Bearer otro'])->assertUnauthorized();
        $this->rpc('tools/list', [], ['Authorization' => 'Bearer secreto'])->assertOk();
    }

    public function test_requests_from_another_website_are_rejected(): void
    {
        $this->rpc('tools/list', [], ['Origin' => 'https://otro-sitio.example'])->assertForbidden();
    }

    public function test_products_come_with_the_cheapest_store_first(): void
    {
        // Sam's vende 2 kg: más barato por kilo aunque el paquete cueste más
        $arroz = $this->product('Arroz', 1, 'kg', [[$this->walmart, 40, 1], [$this->sams, 70, 2]]);

        PriceTrackServer::tool(SearchProducts::class, ['query' => 'arr'])
            ->assertOk()->assertSee(['"total":1', '"provider":"Sam\'s Club","price":70,"presentation":"2 kg"']);

        $cheapest = Price::where('provider_id', $this->sams->id)->value('id');
        PriceTrackServer::tool(GetProduct::class, ['product_id' => $arroz->id])
            ->assertOk()->assertSee('"latest_prices":[{"price_id":'.$cheapest.',');

        PriceTrackServer::tool(GetProduct::class, ['product_id' => 999])->assertHasErrors(['No existe un artículo con id 999.']);
    }

    public function test_creating_a_product_reuses_existing_categories(): void
    {
        Product::create(['name' => 'Yogur', 'size' => 1, 'unit' => 'kg', 'category' => 'Lácteos']);

        PriceTrackServer::tool(CreateProduct::class, ['name' => 'Crema', 'size' => 450, 'unit' => 'ml', 'category' => 'lacteos'])->assertOk();
        $this->assertSame('Lácteos', Product::where('name', 'Crema')->value('category'));

        PriceTrackServer::tool(CreateProduct::class, ['name' => 'Sin unidad', 'size' => 1])->assertHasErrors();
        $this->assertSame(2, Product::count());
    }

    public function test_recording_a_price_uses_the_usual_presentation_and_checks_the_dimension(): void
    {
        $arroz = $this->product('Arroz', 1, 'kg', [[$this->walmart, 40, 1]]);

        PriceTrackServer::tool(RecordPrice::class, ['product_id' => $arroz->id, 'provider_id' => $this->sams->id, 'price' => 35])
            ->assertOk()->assertSee('"is_cheapest_now":true');
        $price = Price::latest('id')->first();
        $this->assertSame('2026-11-19', $price->observed_on->toDateString());
        $this->assertEqualsWithDelta(0.035, $price->unit_price, 0.000001);

        // El mismo día y sin lista se corrige el registro en lugar de duplicarlo
        PriceTrackServer::tool(RecordPrice::class, ['product_id' => $arroz->id, 'provider_id' => $this->sams->id, 'price' => 54, 'bulk' => true])->assertOk();
        $this->assertSame(2, Price::count());
        $this->assertTrue($price->fresh()->bulk);

        PriceTrackServer::tool(RecordPrice::class, ['product_id' => $arroz->id, 'provider_id' => $this->sams->id, 'price' => 20, 'size' => 1, 'unit' => 'l'])
            ->assertHasErrors(['«Arroz» se mide en kg; usa una unidad compatible.']);
    }

    public function test_a_shopping_list_can_be_filled_and_marked_as_purchased(): void
    {
        $arroz = $this->product('Arroz', 1, 'kg', [[$this->walmart, 40, 1], [$this->sams, 70, 2]]);
        $list = ShoppingList::create(['name' => 'Despensa', 'shopped_on' => today()]);

        PriceTrackServer::tool(AddListItem::class, ['list_id' => $list->id, 'product_id' => $arroz->id, 'amount' => 500, 'amount_unit' => 'g'])->assertOk();
        PriceTrackServer::tool(AddListItem::class, ['list_id' => $list->id, 'product_id' => $arroz->id, 'quantity' => 0.5])->assertOk();
        $item = $list->items()->sole();
        $this->assertEqualsWithDelta(1.0, $item->quantity, 0.0001);

        // Se necesita 1 kg: en Sam's hay que llevarse el paquete de 2 kg, así que Walmart sale más barato
        PriceTrackServer::tool(GetShoppingList::class, ['list_id' => $list->id])
            ->assertOk()->assertSee(['"cheapest_store_for_need":"Walmart"', '"estimate":40', '"cost_for_need":70']);

        PriceTrackServer::tool(UpdateListItem::class, ['item_id' => $item->id, 'purchased_at_provider_id' => $this->sams->id])
            ->assertOk()->assertSee(['"bought_at":"Sam\'s Club"', '"paid":70']);
        $this->assertEqualsWithDelta(70.0, $list->fresh()->total(), 0.001);

        PriceTrackServer::tool(UpdateListItem::class, ['item_id' => $item->id, 'purchased' => false])->assertOk();
        $this->assertNull($item->fresh()->price_id);

        $sinPrecio = $this->product('Sal', 1, 'kg');
        $other = $list->items()->create(['product_id' => $sinPrecio->id, 'quantity' => 1]);
        PriceTrackServer::tool(UpdateListItem::class, ['item_id' => $other->id, 'purchased_at_provider_id' => $this->walmart->id])
            ->assertHasErrors(['«Sal» no tiene precio en Walmart; regístralo antes con record-price.']);
    }

    public function test_recipes_can_be_created_planned_and_sent_to_a_list(): void
    {
        $arroz = $this->product('Arroz', 1, 'kg', [[$this->walmart, 40, 1]]);

        PriceTrackServer::tool(CreateRecipe::class, [
            'name' => 'Arroz blanco', 'servings' => 4, 'instructions' => "Lavar\nCocer",
            'ingredients' => [
                ['product_id' => $arroz->id, 'quantity' => 250, 'unit' => 'g'],
                ['name' => 'Sal', 'note' => 'al gusto'],
            ],
        ])->assertOk()->assertSee(['"cost":10', '"ingredients_without_cost":1']);
        $recipe = Recipe::sole();
        $this->assertSame(['Arroz', 'Sal'], $recipe->ingredients->pluck('name')->all());

        // Con artículo, la unidad debe poder convertirse
        PriceTrackServer::tool(CreateRecipe::class, [
            'name' => 'Otra', 'servings' => 2, 'ingredients' => [['product_id' => $arroz->id, 'quantity' => 1, 'unit' => 'cup']],
        ])->assertHasErrors();
        $this->assertSame(1, Recipe::count());

        PriceTrackServer::tool(PlanMeal::class, ['planned_on' => '2026-11-21', 'meal' => 'lunch', 'recipe_id' => $recipe->id, 'servings' => 8])
            ->assertOk()->assertSee('"cost":20');
        PriceTrackServer::tool(PlanMeal::class, ['planned_on' => '2026-11-21', 'meal' => 'lunch'])->assertHasErrors();
        $this->assertSame(1, MealPlanEntry::count());

        // El menú de la semana: 8 porciones = 500 g = media presentación de 1 kg
        PriceTrackServer::tool(AddIngredientsToList::class, ['week' => '2026-11-19'])
            ->assertOk()->assertSee(['"added":1', '"not_added":["Sal"]']);
        $this->assertEqualsWithDelta(0.5, ShoppingList::sole()->items()->sole()->quantity, 0.0001);
    }

    public function test_tasks_can_be_created_updated_and_completed(): void
    {
        PriceTrackServer::tool(CreateTask::class, ['title' => 'Cambiar filtro', 'due_on' => '2026-11-10', 'repeat_every' => 3, 'repeat_unit' => 'month'])
            ->assertOk()->assertSee(['"status":"overdue"', '"priority":"Normal"', '"repeats":"Cada 3 meses"']);
        $task = Task::sole();

        PriceTrackServer::tool(UpdateTask::class, ['task_id' => $task->id, 'priority' => 3])->assertOk();
        $this->assertSame(3, $task->fresh()->priority);
        $this->assertSame('Cambiar filtro', $task->fresh()->title);
        $this->assertSame('2026-11-10', $task->fresh()->due_on->toDateString());

        // La siguiente se cuenta desde el día en que se completa
        PriceTrackServer::tool(CompleteTask::class, ['task_id' => $task->id])->assertOk()->assertSee('"due_on":"2027-02-19"');
        $this->assertTrue($task->fresh()->isDone());
        $this->assertSame(2, Task::count());

        PriceTrackServer::tool(CompleteTask::class, ['task_id' => $task->id, 'done' => false])->assertOk();
        $this->assertFalse($task->fresh()->isDone());
        $this->assertSame(1, Task::count());

        PriceTrackServer::tool(CreateTask::class, ['title' => 'Mal', 'repeat_every' => 2])->assertHasErrors();
    }

    public function test_a_recurring_payment_can_be_marked_as_paid(): void
    {
        $luz = RecurringPayment::create(['name' => 'Luz', 'amount' => 800, 'every' => 2, 'every_unit' => 'month', 'next_due_on' => '2026-11-15']);

        PriceTrackServer::tool(GetFinances::class)->assertOk()->assertSee(['"name":"Luz"', '"status":"overdue"', '"monthly_total":400']);

        PriceTrackServer::tool(MarkPaymentPaid::class, ['recurring_payment_id' => $luz->id, 'amount' => 812.5])
            ->assertOk()->assertSee(['"covered_due_date":"2026-11-15"', '"next_due_on":"2027-01-15"']);
        $this->assertEqualsWithDelta(812.5, $luz->payments()->sole()->amount, 0.001);
    }

    public function test_the_summary_brings_what_matters_today(): void
    {
        Task::create(['title' => 'Regar plantas', 'due_on' => '2026-11-19']);
        Task::create(['title' => 'Más adelante', 'due_on' => '2026-12-01']);
        MealPlanEntry::create(['planned_on' => '2026-11-19', 'meal' => 'dinner', 'title' => 'Tacos']);

        PriceTrackServer::tool(GetSummary::class)
            ->assertOk()
            ->assertSee(['"today":"2026-11-19"', 'Regar plantas', '"what":"Tacos"'])
            ->assertDontSee('Más adelante');
    }
}
