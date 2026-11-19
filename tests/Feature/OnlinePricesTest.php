<?php

namespace Tests\Feature;

use App\Mcp\PriceTrackServer;
use App\Mcp\Tools\FetchOnlinePrices;
use App\Mcp\Tools\SetStoreBranch;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductLink;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\ShoppingList;
use App\Services\PriceFetcher;
use App\Services\PriceFetchException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Precios en línea: enlace del artículo en cada tienda + código postal global.
 * Las respuestas simuladas copian la forma de las reales (Chedraui VTEX, Costco OCC, JSON-LD…).
 */
class OnlinePricesTest extends TestCase
{
    use RefreshDatabase;

    private const MILK = 'https://www.chedraui.com.mx/leche-entera-lala-fresca-plastico-18l-3371188/p';

    private const BANANA = 'https://www.chedraui.com.mx/platano-chiapas-por-kg-3102828/p';

    private const COSTCO = 'https://www.costco.com.mx/Electrodomesticos/Aspiradoras/Dyson-V15/p/694555';

    private Provider $chedraui;

    private Provider $costco;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.price_fetch.resolve_hosts' => false, 'services.price_fetch.browser_url' => null]);
        Http::preventStrayRequests();
        $this->chedraui = Provider::create(['name' => 'Chedraui']);
        $this->costco = Provider::create(['name' => 'Costco']);
        // Como quien ya eligió su tienda: código postal y Chedraui automática (la más cercana que lo tenga)
        Setting::put(Setting::POSTAL_CODE, '77518');
        Setting::put(Setting::CHEDRAUI_STORE, 'auto');
    }

    /** Catálogo VTEX + regiones + simulación del carrito, como responde chedraui.com.mx. */
    /**
     * @param  array<string, array{0: ?int, 1: string}>  $stores  vendedor => [centavos, disponibilidad], de la más cercana a la más lejana
     */
    private function fakeChedraui(float $general = 46.0, int $regional = 4100, ?array $stores = null): void
    {
        // Como en Cancún: la más cercana no envía a domicilio pero tiene el precio de tienda
        $stores ??= [
            'chedrauimx0112' => [$regional, 'cannotBeDelivered'],
            'chedrauimx0255' => [$regional + 250, 'available'],
        ];
        $names = ['chedrauimx0112' => 'CHEDRAUI CANCÚN VILLAS DEL MAR', 'chedrauimx0255' => 'CHEDRAUI CANCÚN KABAH', 'chedrauimx0045' => 'CHEDRAUI CANCÚN PORTILLO'];

        $product = fn (string $name, string $sku, string $unit, float $multiplier, ?string $content = null) => [[
            'productName' => $name,
            'brand' => 'LALA',
            'Contenido del empaque' => $content ? [$content] : null,
            'items' => [[
                'itemId' => $sku, 'ean' => '7501020547047', 'measurementUnit' => $unit, 'unitMultiplier' => $multiplier,
                'sellers' => [['sellerId' => '1', 'commertialOffer' => ['Price' => $general, 'ListPrice' => 46.0, 'AvailableQuantity' => 99999]]],
            ]],
        ]];

        Http::fake([
            'www.chedraui.com.mx/api/catalog_system/pub/products/search/leche-*' => Http::response($product('Leche Entera Lala Fresca Plástico 1.8L', '3371188', 'un', 1.0, '1 pieza con 1.8L'), 206),
            // Así viene en Chedraui: el contenido sin unidad ("355 c/u"); la unidad está en el nombre
            'www.chedraui.com.mx/api/catalog_system/pub/products/search/refresco-12-*' => Http::response($product('Refresco Coca-Cola Original 12 Latas de 355ml c/u', '3061478', 'un', 1.0, '12 piezas de 355 c/u'), 206),
            'www.chedraui.com.mx/api/catalog_system/pub/products/search/refresco-*' => Http::response($product('Refresco Coca-Cola Original', '555', 'un', 1.0, '6 piezas de 355ml'), 206),
            'www.chedraui.com.mx/api/catalog_system/pub/products/search/platano-*' => Http::response($product('Plátano Chiapas por Kg', '3102828', 'kg', 0.2), 206),
            'www.chedraui.com.mx/api/checkout/pub/pickup-points*' => Http::response(['items' => collect(array_keys($stores))->values()->map(fn ($seller, $i) => [
                'distance' => 3.1 + $i * 0.7, 'pickupPoint' => ['id' => $seller.'_PP'.substr($seller, -4), 'friendlyName' => $names[$seller] ?? $seller],
            ])->all()]),
            'www.chedraui.com.mx/api/checkout/pub/regions*' => Http::response([['id' => 'U1cjY2hl', 'sellers' => [['id' => 'chedrauimx0253', 'name' => 'CHEDRAUI SELECTO MERIDA NORTE']]]]),
            // Una sola simulación con un renglón por tienda; el plátano se cobra por 0.2 kg
            'www.chedraui.com.mx/api/checkout/pub/orderForms/simulation*' => fn (Request $request) => Http::response(['items' => collect($request['items'])->map(fn ($item, $i) => [
                'id' => $item['id'], 'seller' => $item['seller'], 'requestIndex' => $i,
                'unitMultiplier' => $item['id'] === '3102828' ? 0.2 : 1.0,
                'sellingPrice' => $item['id'] === '3102828' ? 398 : ($stores[$item['seller']][0] ?? null),
                'availability' => $stores[$item['seller']][1] ?? 'withoutStock',
            ])->all()]),
        ]);
    }

    private function fetch(string $url, ?string $postalCode = null): array
    {
        return app(PriceFetcher::class)->fetch($url, $postalCode);
    }

    private function fetchError(string $url, ?string $postalCode = null): string
    {
        try {
            $this->fetch($url, $postalCode);
        } catch (PriceFetchException $e) {
            return $e->getMessage();
        }
        $this->fail("Se esperaba un error al consultar {$url}");
    }

    public function test_chedraui_uses_the_price_of_the_nearest_store(): void
    {
        $this->fakeChedraui(general: 29.5, regional: 4490);

        // Sin código postal: precio general del catálogo (no es el de ninguna sucursal)
        $this->assertSame(29.5, $this->fetch(self::MILK)['price']);

        // Con código postal: la tienda más cercana, aunque no envíe a domicilio a ese CP
        $found = $this->fetch(self::MILK, '77518');
        $this->assertSame([44.9, 'CHEDRAUI CANCÚN VILLAS DEL MAR'], [$found['price'], $found['store']]);
        $this->assertSame('Leche Entera Lala Fresca Plástico 1.8L', $found['name']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'simulation') && $r['postalCode'] === '77518'
            && array_column($r['items'], 'seller') === ['chedrauimx0112', 'chedrauimx0255']);

        // La tienda elegida manda
        $this->assertSame([47.4, 'CHEDRAUI CANCÚN KABAH'], array_values(array_intersect_key(app(PriceFetcher::class)->fetch(self::MILK, '77518', 'chedrauimx0255'), ['price' => 0, 'store' => 0])));
    }

    public function test_chedraui_skips_stores_without_stock(): void
    {
        $this->fakeChedraui(general: 29.5, stores: [
            'chedrauimx0112' => [null, 'withoutStock'],
            'chedrauimx0045' => [4750, 'withoutStock'],
            'chedrauimx0255' => [4490, 'cannotBeDelivered'],
        ]);

        $this->assertSame(44.9, $this->fetch(self::MILK, '77518')['price']);
    }

    public function test_chedraui_never_falls_back_to_the_general_price(): void
    {
        $this->fakeChedraui(general: 29.5, stores: ['chedrauimx0112' => [null, 'withoutStock']]);

        $this->assertSame('Chedraui no tiene este artículo en tu zona (CP 77518).', $this->fetchError(self::MILK, '77518'));
    }

    public function test_chedraui_weighed_items_are_priced_per_kilo(): void
    {
        $this->fakeChedraui();

        $found = $this->fetch(self::BANANA, '06600');

        // La simulación cobra el "paso" de 0.2 kg ($3.98): se guarda por kg
        $this->assertSame(19.9, $found['price']);
        $this->assertSame(['pack_qty' => 1, 'size' => 1.0, 'unit' => 'kg', 'bulk' => true], $found['presentation']);
    }

    public function test_costco_price_comes_from_its_api(): void
    {
        Http::fake(['www.costco.com.mx/rest/v2/mexico/products/694555*' => Http::response(['code' => '694555', 'name' => 'Dyson V15', 'price' => ['value' => 11199.0]])]);

        $this->assertSame(11199.0, $this->fetch(self::COSTCO)['price']);
        $this->assertSame('Costco', $this->fetch(self::COSTCO)['source']);
    }

    public function test_other_pages_are_read_from_structured_data(): void
    {
        Http::fake([
            'www.homedepot.com.mx/*' => Http::response('<html><head><title>Matraca</title><script type="application/ld+json">'
                .json_encode(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'MATRACA BRUSHLESS 20V', 'offers' => ['@type' => 'Offer', 'priceCurrency' => 'MXN', 'price' => 4149]])
                .'</script></head></html>'),
            'tienda.example.com/graph' => Http::response('<script type="application/ld+json">'
                .json_encode(['@graph' => [['@type' => 'WebPage'], ['@type' => ['Product'], 'name' => 'Aceite 1 L', 'offers' => [['price' => '1,234.50']]]]])
                .'</script>'),
            'tienda.example.com/meta' => Http::response('<title>Arroz 1 kg</title><meta property="product:price:amount" content="34.90">'),
            'www.amazon.com.mx/*' => Http::response('<span id="productTitle"> Café 500 g </span><div id="corePrice_feature_div"><span class="a-offscreen">$1,299.00</span></div>'),
        ]);

        $this->assertSame(4149.0, $this->fetch('https://www.homedepot.com.mx/p/dewalt-matraca-245933')['price']);
        $this->assertSame(['price' => 1234.5, 'name' => 'Aceite 1 L'], array_intersect_key($this->fetch('https://tienda.example.com/graph'), ['price' => 0, 'name' => 0]));
        $this->assertSame(['price' => 34.9, 'name' => 'Arroz 1 kg'], array_intersect_key($this->fetch('https://tienda.example.com/meta'), ['price' => 0, 'name' => 0]));
        $this->assertSame(['price' => 1299.0, 'name' => 'Café 500 g'], array_intersect_key($this->fetch('https://www.amazon.com.mx/dp/B0TEST'), ['price' => 0, 'name' => 0]));
    }

    public function test_bot_protection_is_reported_as_blocked(): void
    {
        Http::fake([
            'www.walmart.com.mx/ip/412' => Http::response(['appId' => 'PXAFlYiz9n', 'blockScript' => '/px/'], 412),
            'www.walmart.com.mx/ip/redirect' => Http::response('<title>Verifica tu identidad</title><div id="px-captcha"></div>'),
            'www.soriana.com/*' => Http::response('<title>Attention Required! | Cloudflare</title><div id="cf-error-details">', 403),
            'www.amazon.com.mx/*' => Http::response('<form action="/errors/validateCaptcha">Continuar a Compras</form>'),
            'www.sams.com.mx/*' => Http::response('', 404),
            'tienda.example.com/*' => Http::response('<html><title>Sin datos</title></html>'),
        ]);

        foreach (['https://www.walmart.com.mx/ip/412', 'https://www.walmart.com.mx/ip/redirect', 'https://www.soriana.com/leche/1.html', 'https://www.amazon.com.mx/dp/B0'] as $url) {
            $this->assertSame('Bloqueado por la tienda (anti-bots).', $this->fetchError($url), $url);
        }
        $this->assertStringContainsString('404', $this->fetchError('https://www.sams.com.mx/x/p/1'));
        $this->assertSame('No encontré el precio en la página.', $this->fetchError('https://tienda.example.com/x'));
    }

    public function test_only_public_https_pages_are_fetched(): void
    {
        foreach (['http://www.chedraui.com.mx/x/p', 'https://192.168.1.10/precio', 'https://localhost/x', 'https://router.local/x', 'ftp://x.com'] as $url) {
            $this->assertSame('El enlace debe ser una página https de la tienda.', $this->fetchError($url), $url);
        }
        Http::assertNothingSent();
    }

    public function test_product_page_saves_links_and_fetches_todays_prices(): void
    {
        $this->fakeChedraui(regional: 4100);
        Http::fake(['www.costco.com.mx/*' => Http::response('', 503)]);
        Setting::put(Setting::POSTAL_CODE, '06600');
        $milk = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);

        $this->put(route('products.links.update', $milk), ['stores' => [$this->chedraui->id => ['url' => self::MILK], $this->costco->id => ['url' => self::COSTCO]]])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $milk->links()->count());

        $this->post(route('products.prices.fetch', $milk))
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Chedraui: $41.00 · 1.8 L') && str_contains($msg, 'Costco: Bloqueado por la tienda'));

        // Sin precio anterior, la presentación sale del nombre en la tienda ("… 1.8L")
        $price = Price::firstOrFail();
        $this->assertSame([41.0, 1.8, 'l', null], [$price->price, $price->size, $price->unit->value, $price->shopping_list_id]);
        $this->assertTrue($price->observed_on->isToday());
        $this->assertNotNull(ProductLink::where('provider_id', $this->costco->id)->value('error'));

        // Quitar un enlace
        $this->put(route('products.links.update', $milk), ['stores' => [$this->chedraui->id => ['url' => self::MILK], $this->costco->id => ['url' => '']]]);
        $this->assertSame(1, $milk->links()->count());

        $this->put(route('products.links.update', $milk), ['stores' => [$this->chedraui->id => ['url' => 'http://inseguro.com/x']]])
            ->assertSessionHasErrors('stores.'.$this->chedraui->id.'.url');
    }

    public function test_presentation_comes_from_the_store_data(): void
    {
        $this->fakeChedraui(general: 89.0);
        Http::fake(['www.costco.com.mx/*' => Http::response(['name' => 'La Lechera Leche Condensada 8 pzas de 375 g', 'price' => ['value' => 239.0],
            'classifications' => [['features' => [['name' => 'Contenido Neto', 'featureValues' => [['value' => '8 pzas de 387 g']]]]]]])]);

        // Chedraui: "Contenido del empaque"; Costco: característica "Contenido Neto" (manda sobre el nombre)
        $this->assertSame(['pack_qty' => 6, 'size' => 355.0, 'unit' => 'ml', 'bulk' => false], $this->fetch('https://www.chedraui.com.mx/refresco-coca-cola-6-pack-555/p')['presentation']);
        $this->assertSame(['pack_qty' => 8, 'size' => 387.0, 'unit' => 'g', 'bulk' => false], $this->fetch(self::COSTCO)['presentation']);

        // Se guarda con esa presentación aunque antes se capturara otra en esa tienda
        $soda = Product::create(['name' => 'Coca-Cola', 'size' => 355, 'unit' => 'ml']);
        Price::create(['product_id' => $soda->id, 'provider_id' => $this->chedraui->id, 'price' => 18, 'size' => 355, 'unit' => 'ml', 'observed_on' => today()->subWeek()]);
        $soda->links()->create(['provider_id' => $this->chedraui->id, 'url' => 'https://www.chedraui.com.mx/refresco-coca-cola-6-pack-555/p']);
        $this->post(route('products.prices.fetch', $soda))->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Chedraui: $41.00 · 6 × 355 ml'));

        // Si el dato de la tienda no encaja (12 piezas para un artículo en ml), se usa el nombre
        $soda->links()->update(['url' => 'https://www.chedraui.com.mx/refresco-12-latas-3061478/p']);
        $this->post(route('products.prices.fetch', $soda))->assertSessionHas('success', fn ($msg) => str_contains($msg, '12 × 355 ml'));
    }

    public function test_previous_presentation_is_kept_when_the_store_does_not_say(): void
    {
        Http::fake(['tienda.example.com/*' => Http::response('<script type="application/ld+json">'.json_encode(['@type' => 'Product', 'name' => 'Leche Lala', 'offers' => ['price' => 260]]).'</script>')]);
        $store = Provider::create(['name' => 'Tienda']);
        $milk = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);
        Price::create(['product_id' => $milk->id, 'provider_id' => $store->id, 'price' => 250, 'pack_qty' => 6, 'size' => 1, 'unit' => 'l', 'observed_on' => today()->subWeek()]);
        $milk->links()->create(['provider_id' => $store->id, 'url' => 'https://tienda.example.com/leche']);

        $this->post(route('products.prices.fetch', $milk));

        $latest = $milk->prices()->latest('id')->first();
        $this->assertSame([260.0, 6, 1.0], [$latest->price, $latest->pack_qty, $latest->size]);
    }

    public function test_sheet_fetches_prices_with_the_list_date_and_keeps_the_purchase(): void
    {
        $this->fakeChedraui(general: 46.0);
        $milk = Product::create(['name' => 'Leche', 'size' => 1.8, 'unit' => 'l']);
        $list = ShoppingList::create(['name' => 'Despensa', 'shopped_on' => today()->subDay()]);
        $old = Price::create(['product_id' => $milk->id, 'provider_id' => $this->chedraui->id, 'price' => 44, 'size' => 1.8, 'unit' => 'l', 'observed_on' => today()->subMonth()]);
        $item = $list->items()->create(['product_id' => $milk->id, 'quantity' => 1, 'price_id' => $old->id]);
        $milk->links()->create(['provider_id' => $this->chedraui->id, 'url' => self::MILK]);

        $res = $this->postJson(route('lists.items.prices.fetch', [$list, $item]))
            ->assertOk()
            ->assertJsonPath('results.0.ok', true)
            ->assertJsonPath('row.links.'.$this->chedraui->id, self::MILK);

        $new = Price::latest('id')->first();
        $this->assertSame($list->id, $new->shopping_list_id);
        $this->assertTrue($new->observed_on->isSameDay(today()->subDay()));
        $this->assertSame($new->id, $item->fresh()->price_id, 'La compra pasa al precio nuevo');
        $this->assertEquals(41, $res->json('row.cells.'.$this->chedraui->id.'.price'), 'Precio de la tienda, no el general del catálogo');
    }

    public function test_pasting_a_link_in_a_cell_saves_it_and_fetches_the_price(): void
    {
        Http::fake(['www.costco.com.mx/*' => Http::response(['name' => 'Leche 6 x 1 L', 'price' => ['value' => 150.0]])]);
        $milk = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);
        $list = ShoppingList::create(['name' => 'Despensa', 'shopped_on' => today()]);
        $item = $list->items()->create(['product_id' => $milk->id, 'quantity' => 6]);

        $this->postJson(route('lists.items.links.store', [$list, $item]), ['provider_id' => $this->costco->id, 'url' => self::COSTCO])
            ->assertOk()
            ->assertJsonPath('results.0.ok', true)
            ->assertJsonPath('row.cells.'.$this->costco->id.'.presentation', '6 × 1 L');

        $this->assertSame(self::COSTCO, $milk->links()->value('url'));

        $this->postJson(route('lists.items.links.store', [$list, $item]), ['provider_id' => $this->costco->id, 'url' => 'no es un enlace'])
            ->assertUnprocessable()->assertJsonValidationErrors('url');
    }

    public function test_fetching_saves_the_brand_and_codes_of_each_store(): void
    {
        $this->fakeChedraui();
        Http::fake([
            'www.costco.com.mx/*' => Http::response(['code' => '581645', 'name' => 'Leche 6 x 1 L', 'price' => ['value' => 150.0],
                'classifications' => [['features' => [['name' => 'Marca', 'featureValues' => [['value' => 'Kirkland Signature']]]]]]]),
            'www.homedepot.com.mx/*' => Http::response('<script type="application/ld+json">'.json_encode([
                '@type' => 'Product', 'name' => 'Agua Bonafont 1.5 L', 'brand' => ['@type' => 'Thing', 'name' => 'Bonafont'],
                'gtin13' => '7501055900084', 'sku' => '245933', 'offers' => ['price' => 15]]).'</script>'),
        ]);
        Product::create(['name' => 'Queso', 'brand' => 'Lala', 'size' => 1, 'unit' => 'kg']);
        $depot = Provider::create(['name' => 'Home Depot']);
        $milk = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);
        foreach ([[$this->chedraui, self::MILK], [$this->costco, self::COSTCO], [$depot, 'https://www.homedepot.com.mx/p/agua-245933']] as [$provider, $url]) {
            $milk->links()->create(['provider_id' => $provider->id, 'url' => $url]);
        }

        $this->post(route('products.prices.fetch', $milk))->assertSessionHasNoErrors();

        $stores = $milk->links()->orderBy('provider_id')->get()->keyBy('provider_id');
        $this->assertSame(['Lala', '7501020547047', '3371188', 'Leche Entera Lala Fresca Plástico 1.8L'], // "LALA" → la marca ya existente
            [$stores[$this->chedraui->id]->brand, $stores[$this->chedraui->id]->barcode, $stores[$this->chedraui->id]->sku, $stores[$this->chedraui->id]->store_name]);
        $this->assertSame(['Kirkland Signature', null, '581645'], [$stores[$this->costco->id]->brand, $stores[$this->costco->id]->barcode, $stores[$this->costco->id]->sku]);
        $this->assertSame(['Bonafont', '7501055900084', '245933'], [$stores[$depot->id]->brand, $stores[$depot->id]->barcode, $stores[$depot->id]->sku]);
    }

    public function test_each_store_can_have_its_own_brand_and_barcode_without_a_link(): void
    {
        $water = Product::create(['name' => 'Agua', 'size' => 1.5, 'unit' => 'l']);
        $walmart = Provider::create(['name' => 'Walmart']);

        $this->put(route('products.links.update', $water), ['stores' => [
            $walmart->id => ['brand' => 'Bonafont', 'barcode' => ' 7501055 900084 ', 'url' => ''],
            $this->costco->id => ['brand' => 'Kirkland', 'barcode' => '', 'url' => ''],
            $this->chedraui->id => ['brand' => '', 'barcode' => '', 'url' => ''],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(['Bonafont', '7501055900084', null], [$water->links()->firstWhere('provider_id', $walmart->id)->brand,
            $water->links()->firstWhere('provider_id', $walmart->id)->barcode, $water->links()->firstWhere('provider_id', $walmart->id)->url]);
        $this->assertSame(2, $water->links()->count(), 'Una tienda sin datos no se guarda');

        // Escanear el código de la versión de Walmart encuentra el artículo
        $this->get(route('products.index', ['q' => '7501055900084']))->assertRedirect(route('products.show', $water));
        $this->getJson(route('products.lookup', ['barcode' => '7501055900084']))->assertJsonPath('product.id', $water->id);
        $list = ShoppingList::create(['name' => 'Despensa', 'shopped_on' => today()]);
        $this->postJson(route('lists.items.store', $list), ['name' => 'Agua', 'size' => 1, 'unit' => 'l', 'barcode' => '7501055900084'])
            ->assertJsonPath('row.product.id', $water->id)
            ->assertJsonPath('row.stores.'.$walmart->id.'.brand', 'Bonafont')
            ->assertJsonPath('product.barcodes', ['7501055900084']);
        $this->assertSame(1, Product::count(), 'No se duplicó el artículo');

        $this->put(route('products.links.update', $water), ['stores' => [$walmart->id => ['barcode' => '12ab']]])
            ->assertSessionHasErrors('stores.'.$walmart->id.'.barcode');
    }

    public function test_stores_that_block_programs_are_read_with_the_real_browser(): void
    {
        config(['services.price_fetch.browser_url' => 'http://browser:3000']);
        $walmartPage = '<script type="application/ld+json">'.json_encode(['@type' => 'Product', 'name' => 'Leche Lala entera 1 l',
            'brand' => ['@type' => 'Brand', 'name' => 'LALA'], 'gtin13' => '00750102051534', 'offers' => [['price' => 37]]]).'</script>';
        Http::fake([
            'browser:3000/html?url=https%3A%2F%2Fwww.walmart.com.mx*' => Http::response($walmartPage, 200, ['X-Final-Url' => 'https://www.walmart.com.mx/ip/leche/00750102051534']),
            'browser:3000/html?url=https%3A%2F%2Fwww.sams.com.mx*' => Http::response('<title>Verifica tu identidad</title>', 200, ['X-Final-Url' => 'https://www.sams.com.mx/blocked?url=x']),
            'browser:3000/html?url=https%3A%2F%2Ftienda.example.com*' => Http::response('<meta property="product:price:amount" content="99.50"><title>Arroz 1 kg</title>'),
            'tienda.example.com/*' => Http::response('<div id="cf-error-details"></div>', 403),
        ]);

        // Walmart va directo al navegador (no se intenta antes sin él)
        $found = $this->fetch('https://www.walmart.com.mx/ip/leche/00750102051534');
        $this->assertSame([37.0, 'LALA', '00750102051534'], [$found['price'], $found['brand'], $found['barcode']]);
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), 'https://www.walmart.com.mx'));

        // Si aun así la tienda pide "Mantén presionado", se informa
        $this->assertStringContainsString('aun con el navegador', $this->fetchError('https://www.sams.com.mx/ip/leche/1'));

        // Cualquier otra tienda que bloquee se reintenta con el navegador
        $this->assertSame(99.5, $this->fetch('https://tienda.example.com/arroz')['price']);
    }

    public function test_without_the_browser_blocked_stores_are_reported(): void
    {
        config(['services.price_fetch.browser_url' => null]);
        Http::fake(['www.walmart.com.mx/*' => Http::response(['appId' => 'PXAFlYiz9n'], 412)]);

        $this->assertSame('Bloqueado por la tienda (anti-bots).', $this->fetchError('https://www.walmart.com.mx/ip/leche/1'));
    }

    public function test_chedraui_store_is_chosen_from_the_stores_near_the_postal_code(): void
    {
        $this->fakeChedraui();
        $this->put(route('settings.update'), ['postal_code' => '77518'])->assertSessionHasNoErrors();

        $this->get(route('providers.index'))->assertOk()
            ->assertSee('Tu tienda Chedraui')
            ->assertSee('CHEDRAUI CANCÚN VILLAS DEL MAR · 3.1 km');

        $this->put(route('settings.update'), ['postal_code' => '77518', 'chedraui_store' => 'chedrauimx0255']);
        $this->assertSame('chedrauimx0255', Setting::value(Setting::CHEDRAUI_STORE));

        // Otro código postal: otras tiendas, se vuelve a elegir
        $this->put(route('settings.update'), ['postal_code' => '06600', 'chedraui_store' => 'chedrauimx0255']);
        $this->assertNull(Setting::value(Setting::CHEDRAUI_STORE));

        $this->put(route('settings.update'), ['postal_code' => '06600', 'chedraui_store' => 'walmart'])->assertSessionHasErrors('chedraui_store');
    }

    public function test_postal_code_is_a_global_setting(): void
    {
        $this->put(route('settings.update'), ['postal_code' => '06600'])->assertSessionHasNoErrors();
        $this->assertSame('06600', Setting::value(Setting::POSTAL_CODE));

        $this->put(route('settings.update'), ['postal_code' => '6600'])->assertSessionHasErrors('postal_code');
        $this->put(route('settings.update'), ['postal_code' => ''])->assertSessionHasNoErrors();
        $this->assertNull(Setting::value(Setting::POSTAL_CODE));

        $this->get(route('providers.index'))->assertOk()->assertSee('Código postal');
    }

    public function test_missing_postal_code_or_branch_is_asked_before_fetching(): void
    {
        $this->fakeChedraui();
        Setting::put(Setting::POSTAL_CODE, null);
        Setting::put(Setting::CHEDRAUI_STORE, null);
        $product = Product::create(['name' => 'Leche', 'size' => 1.8, 'unit' => 'l']);
        $link = $product->links()->create(['provider_id' => $this->chedraui->id, 'url' => self::MILK]);
        $costco = $product->links()->create(['provider_id' => $this->costco->id, 'url' => self::COSTCO]);

        // Sin código postal: 409 con lo que falta, y no se consulta la tienda
        $this->postJson(route('product-links.fetch', $link))->assertStatus(409)
            ->assertJsonPath('needs_branch.chain', 'chedraui')->assertJsonPath('needs_branch.missing', 'postal_code');
        Http::assertNothingSent();
        $this->get(route('lists.index'))->assertSee('"missing":"postal_code"', false);

        // La ventana lista las sucursales del CP y guarda la elegida
        $this->getJson(route('settings.branches', ['chain' => 'chedraui', 'postal_code' => '77518']))->assertOk()
            ->assertJsonPath('branches.0.id', 'chedrauimx0112')->assertJsonPath('branches.1.label', 'CHEDRAUI CANCÚN KABAH · 3.8 km');
        $this->putJson(route('settings.branch'), ['chain' => 'chedraui', 'postal_code' => '77518', 'branch' => 'walmart'])->assertStatus(422);
        $this->putJson(route('settings.branch'), ['chain' => 'chedraui', 'postal_code' => '77518', 'branch' => 'chedrauimx0255'])->assertOk();
        $this->assertSame(['77518', 'chedrauimx0255'], [Setting::value(Setting::POSTAL_CODE), Setting::value(Setting::CHEDRAUI_STORE)]);

        // Y la consulta sigue: el precio de esa sucursal
        $this->postJson(route('product-links.fetch', $link))->assertOk()->assertJsonPath('ok', true)->assertJsonPath('price.price', 43.5);

        // Con otro código postal la sucursal se vuelve a preguntar
        Setting::put(Setting::CHEDRAUI_STORE, null);
        $this->postJson(route('product-links.fetch', $link))->assertStatus(409)->assertJsonPath('needs_branch.missing', 'branch');
        $this->putJson(route('settings.branch'), ['chain' => 'chedraui', 'postal_code' => '77518', 'branch' => 'auto'])->assertOk();
        $this->assertSame('auto', Setting::value(Setting::CHEDRAUI_STORE));
        $this->postJson(route('product-links.fetch', $link))->assertOk()->assertJsonPath('price.price', 41);

        // Las tiendas sin sucursal no preguntan nada
        Setting::put(Setting::POSTAL_CODE, null);
        Http::fake(['www.costco.com.mx/*' => Http::response(['code' => '694555', 'name' => 'Leche', 'price' => ['value' => 30]])]);
        $this->postJson(route('product-links.fetch', $costco))->assertOk();
    }

    public function test_preview_brings_todays_price_without_saving_it(): void
    {
        $this->fakeChedraui();
        $product = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);
        $link = $product->links()->create(['provider_id' => $this->chedraui->id, 'url' => self::MILK]);

        $this->postJson(route('product-links.fetch', $link), ['preview' => true])->assertOk()
            ->assertJson(['ok' => true, 'price' => 41, 'pack_qty' => 1, 'size' => 1.8, 'unit' => 'l', 'bulk' => false, 'provider' => 'Chedraui']);
        $this->assertSame(0, Price::count());

        // Los formularios de captura saben qué artículos tienen dirección en qué tienda
        $this->get(route('prices.create'))->assertOk()->assertSee('Traer el precio de hoy')
            ->assertViewHas('links', fn ($links) => $links[$product->id][$this->chedraui->id]['fetch'] === route('product-links.fetch', $link));
        $this->get(route('products.show', $product))->assertOk()->assertSee('Traer el precio de hoy');
    }

    public function test_sheet_can_fetch_a_single_store(): void
    {
        $this->fakeChedraui();
        Http::fake(['www.costco.com.mx/*' => Http::response(['code' => '694555', 'name' => 'Leche', 'price' => ['value' => 30]])]);
        $product = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);
        $product->links()->create(['provider_id' => $this->chedraui->id, 'url' => self::MILK]);
        $product->links()->create(['provider_id' => $this->costco->id, 'url' => self::COSTCO]);
        $list = ShoppingList::create(['name' => 'Súper', 'shopped_on' => today()]);
        $item = $list->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $res = $this->postJson(route('lists.items.prices.fetch', [$list, $item]), ['provider_id' => $this->costco->id])->assertOk();
        $this->assertSame([$this->costco->id], array_column($res->json('results'), 'provider_id'));
        $this->assertSame(1, Price::count());
    }

    public function test_mcp_lists_and_saves_the_branch(): void
    {
        $this->fakeChedraui();
        Setting::put(Setting::CHEDRAUI_STORE, null);
        $product = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);
        $product->links()->create(['provider_id' => $this->chedraui->id, 'url' => self::MILK]);

        PriceTrackServer::tool(FetchOnlinePrices::class, ['product_id' => $product->id])->assertOk()->assertSee('set-store-branch');
        PriceTrackServer::tool(SetStoreBranch::class, ['chain' => 'chedraui', 'postal_code' => '77518'])->assertOk()->assertSee('CHEDRAUI CANCÚN KABAH');
        PriceTrackServer::tool(SetStoreBranch::class, ['chain' => 'chedraui', 'postal_code' => '77518', 'branch' => 'x'])->assertHasErrors(['no es válida']);
        PriceTrackServer::tool(SetStoreBranch::class, ['chain' => 'chedraui', 'postal_code' => '77518', 'branch' => 'chedrauimx0112'])->assertOk();
        PriceTrackServer::tool(FetchOnlinePrices::class, ['product_id' => $product->id])->assertOk()->assertSee('41.00');
    }

    public function test_bulk_update_lists_every_saved_link(): void
    {
        $milk = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);
        $tv = Product::create(['name' => 'Aspiradora', 'size' => 1, 'unit' => 'item']);
        $milk->links()->create(['provider_id' => $this->chedraui->id, 'url' => self::MILK]);
        $tv->links()->create(['provider_id' => $this->costco->id, 'url' => self::COSTCO]);
        // Solo marca, sin dirección: no se consulta
        $tv->links()->create(['provider_id' => $this->chedraui->id, 'brand' => 'Dyson']);

        $this->getJson(route('products.online-links'))->assertOk()
            ->assertJsonCount(2, 'links')
            ->assertJsonPath('links.0.label', 'Aspiradora — Costco')
            ->assertJsonPath('links.0.chain', null)
            ->assertJsonPath('links.1.chain', 'chedraui');
        $this->get(route('products.index'))->assertOk()->assertSee('Actualizar todos los precios');
    }
}
