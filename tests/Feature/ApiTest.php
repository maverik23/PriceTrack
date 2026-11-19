<?php

namespace Tests\Feature;

use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ShoppingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** API REST de la app móvil (/api/v1). */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    private function price(Product $product, Provider $provider, float $price, array $extra = []): Price
    {
        return Price::create([
            'product_id' => $product->id, 'provider_id' => $provider->id, 'price' => $price,
            'pack_qty' => 1, 'size' => $product->size, 'unit' => $product->unit, 'observed_on' => today(), ...$extra,
        ]);
    }

    public function test_ping_and_token(): void
    {
        $this->getJson('/api/v1/ping')->assertOk()->assertJson(['app' => 'PriceTrack', 'api' => 1]);

        config(['services.api.token' => 'secreto']);
        $this->getJson('/api/v1/ping')->assertStatus(401)->assertJsonStructure(['message']);
        $this->getJson('/api/v1/ping', ['Authorization' => 'Bearer otro'])->assertStatus(401);
        $this->getJson('/api/v1/ping', ['Authorization' => 'Bearer secreto'])->assertOk();
    }

    public function test_api_routes_do_not_take_the_names_of_the_web_routes(): void
    {
        $list = ShoppingList::create(['name' => 'Despensa', 'shopped_on' => today()]);

        $this->assertStringEndsWith("/lists/{$list->id}", route('lists.show', $list));
        $this->assertStringNotContainsString('/api/', route('products.index'));
    }

    public function test_errors_are_json_even_without_accept_header(): void
    {
        $this->get('/api/v1/products/999999')->assertStatus(404)->assertJsonStructure(['message']);
        $this->post('/api/v1/products', ['name' => ''])->assertStatus(422)->assertJsonValidationErrors(['name', 'size', 'unit']);
    }

    public function test_products_crud_search_and_detail(): void
    {
        $walmart = Provider::create(['name' => 'Walmart']);

        $id = $this->postJson('/api/v1/products', ['name' => 'Leche entera', 'brand' => 'Lala', 'size' => 1, 'unit' => 'l', 'barcode' => '7501020515343'])
            ->assertCreated()->assertJsonPath('data.name', 'Leche entera')->json('data.id');
        $product = Product::findOrFail($id);
        $this->price($product, $walmart, 28.5);
        Product::create(['name' => 'Arroz', 'size' => 1, 'unit' => 'kg']);

        // Solo cambia lo que se manda
        $this->putJson("/api/v1/products/{$id}", ['category' => 'Lácteos'])
            ->assertOk()->assertJsonPath('data.category', 'Lácteos')->assertJsonPath('data.brand', 'Lala');

        $this->getJson('/api/v1/products?q=lech')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.latest_prices.0.price', 28.5)
            ->assertJsonPath('meta.total', 1);
        // Un código de barras da solo ese artículo
        $this->getJson('/api/v1/products?q=7501020515343')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/products?per_page=1&page=2')->assertOk()->assertJsonPath('meta.last_page', 2)->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/products/{$id}")->assertOk()
            ->assertJsonPath('data.prices.0.provider', 'Walmart')
            ->assertJsonPath('data.prices.0.unit_price', 28.5)
            ->assertJsonPath('data.compare_label', 'L');
        $this->getJson('/api/v1/products?detail=1')->assertOk()->assertJsonStructure(['data' => [['prices', 'links', 'attachments']]]);

        $this->getJson('/api/v1/products/catalog')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.barcodes', ['7501020515343']);
        $this->getJson('/api/v1/products/options')->assertOk()
            ->assertJsonPath('categories', ['Lácteos'])->assertJsonPath('providers.0.name', 'Walmart')->assertJsonPath('units.kg.base', 1000);

        // El artículo en una tienda
        $this->putJson("/api/v1/products/{$id}/links/{$walmart->id}", ['url' => 'https://super.walmart.com.mx/ip/leche/123', 'brand' => 'lala'])
            ->assertOk()->assertJsonPath('data.links.0.brand', 'Lala');
        $this->putJson("/api/v1/products/{$id}/links/{$walmart->id}", ['url' => null, 'brand' => null])
            ->assertOk()->assertJsonCount(0, 'data.links');

        $this->deleteJson("/api/v1/products/{$id}")->assertOk();
        $this->assertNull(Product::find($id));
    }

    public function test_providers_and_prices(): void
    {
        $id = $this->postJson('/api/v1/providers', ['name' => 'Home Depot'])->assertCreated()
            ->assertJsonPath('data.groceries', false)->json('data.id');
        $this->putJson("/api/v1/providers/{$id}", ['notes' => 'Coapa'])->assertOk()->assertJsonPath('data.name', 'Home Depot');
        $this->postJson('/api/v1/providers', ['name' => 'Home Depot'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->getJson('/api/v1/providers')->assertOk()->assertJsonPath('data.0.prices_count', 0)->assertJsonStructure(['postal_code', 'chains']);
        $this->putJson('/api/v1/settings/postal-code', ['postal_code' => '04100'])->assertOk()->assertJsonPath('postal_code', '04100');

        $product = Product::create(['name' => 'Tornillos', 'size' => 100, 'unit' => 'item']);
        $priceId = $this->postJson('/api/v1/prices', [
            'product_id' => $product->id, 'provider_id' => $id, 'price' => 89, 'size' => 100, 'unit' => 'item', 'observed_on' => today()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.unit_price', 0.89)->json('data.id');
        // Otra dimensión que la del artículo
        $this->postJson('/api/v1/prices', [
            'product_id' => $product->id, 'provider_id' => $id, 'price' => 89, 'size' => 1, 'unit' => 'kg', 'observed_on' => today()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('unit');

        $this->putJson("/api/v1/prices/{$priceId}", ['price' => 95])->assertOk()->assertJsonPath('data.price', 95)->assertJsonPath('data.size', 100);
        $this->getJson("/api/v1/prices?product_id={$product->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.product', 'Tornillos');
        $this->deleteJson("/api/v1/prices/{$priceId}")->assertOk();
        $this->deleteJson("/api/v1/providers/{$id}")->assertOk();
        $this->assertSame(0, Provider::count());
    }

    public function test_list_sheet_is_calculated_like_the_web_grid(): void
    {
        $walmart = Provider::create(['name' => 'Walmart']);
        $sams = Provider::create(['name' => "Sam's Club"]);
        $costco = Provider::create(['name' => 'Costco']);
        $salsa = Product::create(['name' => 'Salsa de tomate', 'size' => 345, 'unit' => 'ml']);
        $ejote = Product::create(['name' => 'Ejote', 'size' => 1, 'unit' => 'kg']);
        $this->price($salsa, $walmart, 24.50);
        $samsPrice = $this->price($salsa, $sams, 62.10, ['pack_qty' => 3]);
        $this->price($ejote, $walmart, 54, ['bulk' => true]);

        $listId = $this->postJson('/api/v1/lists', ['name' => 'Despensa', 'shopped_on' => today()->toDateString()])
            ->assertCreated()->json('data.list.id');
        $salsaItem = $this->postJson("/api/v1/lists/{$listId}/items", ['product_id' => $salsa->id, 'quantity' => 2])->assertCreated()->json('row.id');
        $ejoteItem = $this->postJson("/api/v1/lists/{$listId}/items", ['product_id' => $ejote->id, 'quantity' => 0.61])->assertCreated()->json('row.id');
        // Agregar el mismo artículo otra vez no lo duplica (así se puede repetir al sincronizar)
        $this->postJson("/api/v1/lists/{$listId}/items", ['product_id' => $salsa->id, 'quantity' => 5])->assertOk()->assertJsonPath('existing', true);

        $sheet = $this->getJson("/api/v1/lists/{$listId}")->assertOk()->json('data');

        // Solo se ven las tiendas con datos en la lista
        $visible = collect($sheet['providers'])->where('visible', true)->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Walmart', "Sam's Club"], $visible);

        $row = collect($sheet['rows'])->firstWhere('id', $salsaItem);
        $this->assertSame('690 ml', $row['need_label']);
        $cells = collect($row['cells'])->keyBy('provider_id');
        // 2 salsas: 2 frascos en Walmart ($49) o un paquete de 3 en Sam's ($62.10)
        $this->assertEqualsWithDelta(49.0, $cells[$walmart->id]['cost_for_need'], 0.001);
        $this->assertSame(2, $cells[$walmart->id]['packs']);
        $this->assertEqualsWithDelta(62.10, $cells[$sams->id]['cost_for_need'], 0.001);
        // Sam's es el más barato por litro (★) pero conviene llevarlo de Walmart
        $this->assertTrue($cells[$sams->id]['cheapest']);
        $this->assertTrue($cells[$walmart->id]['best_buy']);
        $this->assertSame(18, $cells[$walmart->id]['diff']);
        $this->assertEqualsWithDelta(49.0, $row['estimated'], 0.001);

        // A granel se cobra lo justo: 610 g a $54/kg
        $ejoteRow = collect($sheet['rows'])->firstWhere('id', $ejoteItem);
        $this->assertEqualsWithDelta(32.94, $ejoteRow['estimated'], 0.001);
        $this->assertNull($ejoteRow['cells'][0]['packs']);
        $this->assertEqualsWithDelta(81.94, $sheet['summary']['total_estimated'], 0.001);
        $this->assertSame(0, $sheet['summary']['purchased']);

        // Comprar la salsa en Sam's
        $this->patchJson("/api/v1/lists/{$listId}/items/{$salsaItem}", ['price_id' => $samsPrice->id])->assertOk();
        $sheet = $this->getJson("/api/v1/lists/{$listId}")->json('data');
        $row = collect($sheet['rows'])->firstWhere('id', $salsaItem);
        $this->assertEqualsWithDelta(62.10, $row['subtotal'], 0.001);
        $this->assertSame('3 × 345 ml', $row['bought_label']);
        $this->assertSame($samsPrice->id, $row['selected']['id']);
        $this->assertEqualsWithDelta(62.10, $sheet['summary']['total_paid'], 0.001);
        $this->assertEqualsWithDelta(95.04, $sheet['summary']['total_estimated'], 0.001);
        // En Walmart habría salido en $49: se pudo ahorrar 13.10
        $this->assertEqualsWithDelta(49.0, $sheet['summary']['cheapest_total'], 0.001);
        $this->assertEqualsWithDelta(13.10, $sheet['summary']['possible_savings'], 0.001);
        $samsStore = collect($sheet['providers'])->firstWhere('id', $sams->id);
        $this->assertSame(1, $samsStore['bought_count']);
        $this->assertFalse($samsStore['can_hide']);

        // El ticket manda sobre la suma de lo marcado
        $this->putJson("/api/v1/lists/{$listId}/tickets/{$sams->id}", ['total' => 60])->assertOk();
        $this->assertEqualsWithDelta(60.0, $this->getJson("/api/v1/lists/{$listId}")->json('data.summary.total_paid'), 0.001);

        // Ocultar Walmart: deja de contar para el estimado del ejote
        $this->putJson("/api/v1/lists/{$listId}/providers", ['added' => [$costco->id], 'hidden' => [$walmart->id]])->assertOk();
        $sheet = $this->getJson("/api/v1/lists/{$listId}")->json('data');
        $this->assertEqualsCanonicalizing(['Costco', "Sam's Club"], collect($sheet['providers'])->where('visible', true)->pluck('name')->all());
        $this->assertNull(collect($sheet['rows'])->firstWhere('id', $ejoteItem)['estimated']);

        // Precio capturado en la hoja, desde la app
        $this->postJson("/api/v1/lists/{$listId}/items/{$ejoteItem}/prices", ['provider_id' => $costco->id, 'price' => 99, 'size' => 2, 'unit' => 'kg'])
            ->assertOk()->assertJsonPath('price.provider_id', $costco->id);
        // Necesitas 610 g y Costco vende 2 kg: pagas los 2 kg
        $this->assertEqualsWithDelta(99.0, collect($this->getJson("/api/v1/lists/{$listId}")->json('data.rows'))->firstWhere('id', $ejoteItem)['estimated'], 0.001);
    }

    public function test_lists_index_done_update_and_delete(): void
    {
        $walmart = Provider::create(['name' => 'Walmart']);
        $arroz = Product::create(['name' => 'Arroz', 'size' => 1, 'unit' => 'kg']);
        $price = $this->price($arroz, $walmart, 30);
        $old = ShoppingList::create(['name' => 'Semana pasada', 'shopped_on' => today()->subWeek()]);
        $old->items()->create(['product_id' => $arroz->id, 'quantity' => 2, 'price_id' => $price->id]);

        // Comprar el único artículo la dejó comprada
        $this->getJson('/api/v1/lists')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('counts.done', 1);
        $this->getJson('/api/v1/lists?status=all')->assertOk()
            ->assertJsonPath('data.0.total_paid', 60)->assertJsonPath('data.0.bought_at', ['Walmart'])->assertJsonPath('data.0.done', true);

        $newId = $this->postJson('/api/v1/lists', ['name' => 'Esta semana', 'shopped_on' => today()->toDateString(), 'copy_from' => $old->id])
            ->assertCreated()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.quantity', 2)->json('data.list.id');
        $this->putJson("/api/v1/lists/{$newId}", ['name' => 'Súper del sábado'])->assertOk()->assertJsonPath('data.list.name', 'Súper del sábado');
        $this->postJson("/api/v1/lists/{$newId}/done", ['done' => 1])->assertOk()->assertJsonPath('data.done', true);
        $this->postJson("/api/v1/lists/{$newId}/done", ['done' => 0])->assertOk()->assertJsonPath('data.done', false);

        $itemId = ShoppingList::find($newId)->items()->value('id');
        $this->deleteJson("/api/v1/lists/{$newId}/items/{$itemId}")->assertOk();
        $this->deleteJson("/api/v1/lists/{$newId}")->assertOk();
        $this->assertNull(ShoppingList::find($newId));
        $this->assertNotNull(Price::find($price->id), 'Los precios se conservan');
    }

    public function test_pantry_dashboard_and_photos(): void
    {
        Storage::fake('local');
        $walmart = Provider::create(['name' => 'Walmart']);
        $sal = Product::create(['name' => 'Sal', 'size' => 1, 'unit' => 'kg']);
        $this->price($sal, $walmart, 20);

        $this->postJson("/api/v1/products/{$sal->id}/pantry", ['have' => 1, 'qty' => '500 g'])->assertOk()->assertJsonPath('row.qty', '500 g');
        $this->getJson('/api/v1/pantry')->assertOk()
            ->assertJsonPath('counts.stocked', 1)->assertJsonPath('data.0.value', 10)->assertJsonPath('value', 10);
        $this->postJson("/api/v1/products/{$sal->id}/pantry", ['have' => 0])->assertOk()->assertJsonPath('row.pantry', 'out');
        $this->postJson("/api/v1/products/{$sal->id}/pantry/list")->assertOk()->assertJsonPath('row.in_list', ShoppingList::first()->name);

        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.counts.products', 1)
            ->assertJsonPath('data.groceries.recent_lists.0.items', 1)
            ->assertJsonStructure(['data' => ['today', 'groceries' => ['spent_this_month', 'price_changes'], 'kitchen', 'home' => ['due_tasks'], 'finance' => ['net_worth']]]);

        // Foto del artículo: se sube, se sirve con la API y se quita
        $photoId = $this->post("/api/v1/products/{$sal->id}/attachments", ['files' => [UploadedFile::fake()->image('sal.jpg')]])
            ->assertOk()->assertJsonCount(1, 'data.attachments')->json('data.photo.id');
        $this->assertSame("attachments/{$photoId}", $this->getJson("/api/v1/products/{$sal->id}")->json('data.photo.url'));
        $this->get("/api/v1/attachments/{$photoId}")->assertOk();
        $this->deleteJson("/api/v1/attachments/{$photoId}")->assertOk()->assertJsonStructure(['message']);
        $this->assertNull($this->getJson("/api/v1/products/{$sal->id}")->json('data.photo'));
    }
}
