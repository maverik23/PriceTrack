<?php

namespace Tests\Feature;

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
        config(['services.price_fetch.resolve_hosts' => false]);
        Http::preventStrayRequests();
        $this->chedraui = Provider::create(['name' => 'Chedraui']);
        $this->costco = Provider::create(['name' => 'Costco']);
    }

    /** Catálogo VTEX + regiones + simulación del carrito, como responde chedraui.com.mx. */
    private function fakeChedraui(float $general = 46.0, int $regional = 4100): void
    {
        $product = fn (string $name, string $sku, string $unit, float $multiplier, ?string $content = null) => [[
            'productName' => $name,
            'Contenido del empaque' => $content ? [$content] : null,
            'items' => [[
                'itemId' => $sku, 'measurementUnit' => $unit, 'unitMultiplier' => $multiplier,
                'sellers' => [['sellerId' => '1', 'commertialOffer' => ['Price' => $general, 'ListPrice' => 46.0, 'AvailableQuantity' => 99999]]],
            ]],
        ]];

        Http::fake([
            'www.chedraui.com.mx/api/catalog_system/pub/products/search/leche-*' => Http::response($product('Leche Entera Lala Fresca Plástico 1.8L', '3371188', 'un', 1.0, '1 pieza con 1.8L'), 206),
            // Así viene en Chedraui: el contenido sin unidad ("355 c/u"); la unidad está en el nombre
            'www.chedraui.com.mx/api/catalog_system/pub/products/search/refresco-12-*' => Http::response($product('Refresco Coca-Cola Original 12 Latas de 355ml c/u', '3061478', 'un', 1.0, '12 piezas de 355 c/u'), 206),
            'www.chedraui.com.mx/api/catalog_system/pub/products/search/refresco-*' => Http::response($product('Refresco Coca-Cola Original', '555', 'un', 1.0, '6 piezas de 355ml'), 206),
            'www.chedraui.com.mx/api/catalog_system/pub/products/search/platano-*' => Http::response($product('Plátano Chiapas por Kg', '3102828', 'kg', 0.2), 206),
            'www.chedraui.com.mx/api/checkout/pub/regions*' => Http::response([['id' => 'U1cjY2hl', 'sellers' => [['id' => 'chedrauimx0232', 'name' => 'Chedraui Insurgentes']]]]),
            'www.chedraui.com.mx/api/checkout/pub/orderForms/simulation*' => fn (Request $request) => Http::response(['items' => [[
                'id' => $request['items'][0]['id'],
                'unitMultiplier' => $request['items'][0]['id'] === '3102828' ? 0.2 : 1.0,
                'sellingPrice' => $request['items'][0]['id'] === '3102828' ? 398 : $regional,
            ]]]),
        ]);
    }

    private function fetch(string $url, ?string $postalCode = null): array
    {
        return app(PriceFetcher::class)->fetch($url, $postalCode);
    }

    private function fetchError(string $url): string
    {
        try {
            $this->fetch($url);
        } catch (PriceFetchException $e) {
            return $e->getMessage();
        }
        $this->fail("Se esperaba un error al consultar {$url}");
    }

    public function test_chedraui_uses_the_price_of_the_postal_code_zone(): void
    {
        $this->fakeChedraui(general: 46.0, regional: 4100);

        $this->assertSame(46.0, $this->fetch(self::MILK)['price']);

        $found = $this->fetch(self::MILK, '06600');
        $this->assertSame(41.0, $found['price']);
        $this->assertSame('Leche Entera Lala Fresca Plástico 1.8L', $found['name']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'simulation')
            && $r['postalCode'] === '06600' && $r['items'][0]['seller'] === 'chedrauimx0232');
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

        $this->put(route('products.links.update', $milk), ['links' => [$this->chedraui->id => self::MILK, $this->costco->id => self::COSTCO]])
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
        $this->put(route('products.links.update', $milk), ['links' => [$this->chedraui->id => self::MILK, $this->costco->id => '']]);
        $this->assertSame(1, $milk->links()->count());

        $this->put(route('products.links.update', $milk), ['links' => [$this->chedraui->id => 'http://inseguro.com/x']])
            ->assertSessionHasErrors('links.'.$this->chedraui->id);
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
        $this->post(route('products.prices.fetch', $soda))->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Chedraui: $89.00 · 6 × 355 ml'));

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
        $this->assertEquals(46, $res->json('row.cells.'.$this->chedraui->id.'.price'));
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

    public function test_postal_code_is_a_global_setting(): void
    {
        $this->put(route('settings.update'), ['postal_code' => '06600'])->assertSessionHasNoErrors();
        $this->assertSame('06600', Setting::value(Setting::POSTAL_CODE));

        $this->put(route('settings.update'), ['postal_code' => '6600'])->assertSessionHasErrors('postal_code');
        $this->put(route('settings.update'), ['postal_code' => ''])->assertSessionHasNoErrors();
        $this->assertNull(Setting::value(Setting::POSTAL_CODE));

        $this->get(route('providers.index'))->assertOk()->assertSee('Código postal');
    }
}
