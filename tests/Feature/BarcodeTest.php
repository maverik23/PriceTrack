<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ShoppingList;
use App\Services\BarcodeLookup;
use App\Support\Barcode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Buscar y agregar artículos por código de barras, con datos de internet.
 */
class BarcodeTest extends TestCase
{
    use RefreshDatabase;

    private const LALA = '7501020515343';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** Open Food Facts conoce el código; las demás fuentes no. */
    private function fakeOpenFoodFacts(array $product, string $code = self::LALA): void
    {
        Http::fake([
            "world.openfoodfacts.org/api/v2/product/{$code}.json*" => Http::response(['code' => $code, 'status' => 1, 'product' => $product]),
            '*/api/v2/product/*' => Http::response(['status' => 0, 'status_verbose' => 'product not found'], 404),
            'api.upcitemdb.com/*' => Http::response(['code' => 'OK', 'total' => 0, 'items' => []]),
        ]);
    }

    private function lookup(string $code)
    {
        return $this->getJson(route('products.lookup', ['barcode' => $code]));
    }

    public function test_barcodes_are_normalized(): void
    {
        $this->assertSame('7501020515343', Barcode::normalize(' 750-1020 515343 '));
        $this->assertSame('0012345678905', Barcode::normalize('012345678905')); // UPC-A → EAN-13
        $this->assertSame('7501020515343', Barcode::normalize('07501020515343')); // GTIN-14
        $this->assertNull(Barcode::normalize('  '));
        $this->assertNull(Barcode::fromSearch('Leche 345'));
        $this->assertSame('12345678', Barcode::fromSearch('1234 5678'));
    }

    #[DataProvider('quantities')]
    public function test_package_quantities_are_parsed(string $text, ?array $expected): void
    {
        $parsed = BarcodeLookup::parseQuantity($text);

        $this->assertSame($expected, $parsed ? [$parsed['size'], $parsed['unit'], $parsed['label']] : null);
    }

    public static function quantities(): array
    {
        return [
            ['1 L', [1.0, 'l', '1 L']],
            ['345ml', [345.0, 'ml', '345 ml']],
            ['1,5 kg', [1.5, 'kg', '1.5 kg']],
            ['6 x 355 ml', [2130.0, 'ml', '6 × 355 ml']],
            ['12 × 1 lt', [12.0, 'l', '12 × 1 L']],
            ['75 cl', [750.0, 'ml', '750 ml']],
            ['30 piezas', [30.0, 'item', '30 pza']],
            ['355 ml (12 fl oz)', [355.0, 'ml', '355 ml']],
            ['12 fl oz', [354.9, 'ml', '354.9 ml']],
            ['2 lbs', [907.2, 'g', '907.2 g']],
            ['1 lata', null],
            ['', null],
        ];
    }

    public function test_lookup_fills_the_form_with_open_food_facts_data(): void
    {
        Product::create(['name' => 'Queso', 'brand' => 'Lala', 'category' => 'Lácteos', 'size' => 400, 'unit' => 'g']);
        $this->fakeOpenFoodFacts([
            'product_name_es' => 'Leche entera',
            'product_name' => 'Whole milk',
            'brands' => 'LALA,Grupo Lala',
            'quantity' => '1 L',
            'categories_tags' => ['en:dairies', 'en:milks'],
            'image_front_small_url' => 'https://images.openfoodfacts.org/leche.200.jpg',
        ]);

        $this->lookup('750 1020 515343')
            ->assertOk()
            ->assertJsonPath('barcode', self::LALA)
            ->assertJsonPath('product', null)
            ->assertJsonPath('error', null)
            ->assertJsonPath('suggestion.name', 'Leche entera 1 L')
            ->assertJsonPath('suggestion.brand', 'Lala') // se reusa la marca existente
            ->assertJsonPath('suggestion.category', 'Lácteos')
            ->assertJsonPath('suggestion.size', 1)
            ->assertJsonPath('suggestion.unit', 'l')
            ->assertJsonPath('suggestion.presentation', '1 L')
            ->assertJsonPath('suggestion.image', 'https://images.openfoodfacts.org/leche.200.jpg')
            ->assertJsonPath('suggestion.source', 'Open Food Facts');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'openfoodfacts.org/api/v2/product/'.self::LALA)
            && $request->hasHeader('User-Agent', 'PriceTrack/1.0 (self-hosted)'));
    }

    public function test_name_already_with_size_is_kept_and_other_foods_go_to_pantry(): void
    {
        $this->fakeOpenFoodFacts([
            'product_name' => 'Salsa Catsup 680g',
            'brands' => 'Del Monte',
            'product_quantity' => 680,
            'product_quantity_unit' => 'g',
            'categories_tags' => ['en:condiments', 'en:sauces'],
        ]);

        $this->lookup(self::LALA)
            ->assertJsonPath('suggestion.name', 'Salsa Catsup 680g')
            ->assertJsonPath('suggestion.presentation', '680 g')
            ->assertJsonPath('suggestion.category', 'Abarrotes');
    }

    public function test_falls_back_to_upcitemdb_for_products_not_in_open_food_facts(): void
    {
        Http::fake([
            '*/api/v2/product/*' => Http::response(['status' => 0], 404),
            'api.upcitemdb.com/*' => Http::response(['code' => 'OK', 'total' => 1, 'items' => [[
                'title' => 'Kirkland Signature Extra Virgin Olive Oil, 2 L',
                'brand' => 'Kirkland Signature',
                'size' => '',
                'category' => 'Food, Beverages &amp; Tobacco > Food Items > Cooking &amp; Baking Ingredients > Cooking Oils',
                'images' => ['https://example.com/oil.jpg'],
            ]]]),
        ]);

        $this->lookup('0096619756803')
            ->assertJsonPath('suggestion.name', 'Kirkland Signature Extra Virgin Olive Oil, 2 L')
            ->assertJsonPath('suggestion.brand', 'Kirkland Signature')
            ->assertJsonPath('suggestion.presentation', '2 L')
            ->assertJsonPath('suggestion.category', 'Abarrotes')
            ->assertJsonPath('suggestion.source', 'UPCitemdb');
    }

    public function test_unknown_barcodes_return_nothing_and_are_cached(): void
    {
        Http::fake([
            '*/api/v2/product/*' => Http::response(['status' => 0], 404),
            'api.upcitemdb.com/*' => Http::response(['code' => 'OK', 'total' => 0, 'items' => []]),
        ]);

        $this->lookup(self::LALA)->assertOk()->assertJsonPath('suggestion', null)->assertJsonPath('error', null);
        $this->lookup(self::LALA)->assertOk()->assertJsonPath('suggestion', null);

        Http::assertSentCount(5); // 4 bases de Open Facts + UPCitemdb, una sola vez
    }

    public function test_network_failures_are_reported_and_not_cached(): void
    {
        Http::fake([
            'world.openfoodfacts.org/*' => Http::failedConnection(),
            '*/api/v2/product/*' => Http::response(['status' => 0], 404),
            'api.upcitemdb.com/*' => Http::response(['code' => 'TOO_FAST'], 429),
        ]);

        $this->lookup(self::LALA)->assertOk()
            ->assertJsonPath('suggestion', null)
            ->assertJsonPath('error', 'No se pudo consultar internet. Intenta de nuevo en un momento.');
        $this->lookup(self::LALA);

        Http::assertSentCount(8);
    }

    public function test_lookup_can_be_disabled(): void
    {
        config(['services.barcode_lookup.enabled' => false]);

        $this->lookup(self::LALA)->assertOk()->assertJsonPath('suggestion', null)->assertJsonPath('error', fn ($e) => str_contains($e, 'desactivada'));
        Http::assertNothingSent();
    }

    public function test_a_saved_barcode_is_found_without_going_online(): void
    {
        $milk = Product::create(['name' => 'Leche', 'barcode' => self::LALA, 'size' => 1, 'unit' => 'l']);

        $this->lookup(self::LALA)
            ->assertJsonPath('product.id', $milk->id)
            ->assertJsonPath('product.url', route('products.show', $milk, false))
            ->assertJsonPath('suggestion', null);
        Http::assertNothingSent();
    }

    public function test_invalid_barcodes_are_rejected(): void
    {
        $this->lookup('leche')->assertUnprocessable()->assertJsonValidationErrors('barcode');
        $this->lookup('12345')->assertUnprocessable();
    }

    public function test_products_are_saved_with_a_unique_normalized_barcode(): void
    {
        $this->post(route('products.store'), ['name' => 'Leche', 'barcode' => '750 1020 515343', 'size' => 1, 'unit' => 'l'])
            ->assertSessionHasNoErrors();
        $milk = Product::firstWhere('name', 'Leche');
        $this->assertSame(self::LALA, $milk->barcode);

        $this->post(route('products.store'), ['name' => 'Otra', 'barcode' => self::LALA, 'size' => 1, 'unit' => 'l'])
            ->assertSessionHasErrors(['barcode' => 'Ese código de barras ya es de otro artículo.']);
        $this->post(route('products.store'), ['name' => 'Otra', 'barcode' => 'abc123', 'size' => 1, 'unit' => 'l'])
            ->assertSessionHasErrors('barcode');

        // Editar el mismo artículo conserva su código; vaciarlo lo quita
        $this->put(route('products.update', $milk), ['name' => 'Leche entera', 'barcode' => self::LALA, 'size' => 1, 'unit' => 'l'])
            ->assertSessionHasNoErrors();
        $this->put(route('products.update', $milk), ['name' => 'Leche entera', 'barcode' => '', 'size' => 1, 'unit' => 'l'])
            ->assertSessionHasNoErrors();
        $this->assertNull($milk->fresh()->barcode);
    }

    public function test_searching_a_barcode_opens_the_product_or_offers_to_create_it(): void
    {
        $milk = Product::create(['name' => 'Leche', 'barcode' => self::LALA, 'size' => 1, 'unit' => 'l']);

        $this->get(route('products.index', ['q' => '07501020515343']))->assertRedirect(route('products.show', $milk));

        $this->get(route('products.index', ['q' => '7501000111206']))
            ->assertOk()
            ->assertSee(route('products.create', ['barcode' => '7501000111206']), false);

        $this->get(route('products.index', ['q' => 'Leche']))->assertOk()->assertSee('Leche');
    }

    public function test_create_form_preloads_the_barcode_and_looks_it_up(): void
    {
        $response = $this->get(route('products.create', ['barcode' => '7501000111206']))->assertOk();

        $this->assertTrue($response->viewData('autoLookup'));
        $this->assertSame('7501000111206', $response->viewData('product')->barcode);
        $response->assertSee('x-data="productForm(', false)->assertSee('7501000111206');

        $this->assertFalse($this->get(route('products.create'))->viewData('autoLookup'));
    }

    public function test_list_sheet_creates_a_product_from_a_scanned_barcode(): void
    {
        $list = ShoppingList::create(['name' => 'Despensa', 'shopped_on' => '2026-11-10']);
        Product::create(['name' => 'Queso', 'brand' => 'Lala', 'category' => 'Lácteos', 'size' => 400, 'unit' => 'g']);

        $this->postJson(route('lists.items.store', $list), [
            'name' => 'Leche entera 1 L', 'size' => 1, 'unit' => 'l',
            'barcode' => '7501020515343', 'brand' => 'LALA', 'category' => 'lacteos',
        ])->assertCreated()->assertJsonPath('product.barcode', self::LALA);

        $milk = Product::firstWhere('barcode', self::LALA);
        $this->assertSame(['Leche entera 1 L', 'Lala', 'Lácteos'], [$milk->name, $milk->brand, $milk->category]);

        // Si el código ya es de un artículo se usa ese, sin duplicarlo
        $other = ShoppingList::create(['name' => 'Otra', 'shopped_on' => '2026-11-11']);
        $this->postJson(route('lists.items.store', $other), ['name' => 'Leche', 'size' => 1, 'unit' => 'l', 'barcode' => self::LALA])
            ->assertCreated()->assertJsonPath('row.product.id', $milk->id);
        $this->assertSame(1, Product::where('name', 'like', 'Leche%')->count());
    }

    public function test_list_sheet_knows_the_barcodes_and_the_lookup_url(): void
    {
        $list = ShoppingList::create(['name' => 'Despensa', 'shopped_on' => '2026-11-10']);
        Product::create(['name' => 'Leche', 'barcode' => self::LALA, 'size' => 1, 'unit' => 'l']);

        $grid = $this->get(route('lists.show', $list))->assertOk()->viewData('grid');

        $this->assertSame(self::LALA, $grid['products'][0]['barcode']);
        $this->assertSame(route('products.lookup', [], false), $grid['urls']['lookup']);
    }
}
