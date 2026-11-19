<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Provider;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductPhotoTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xE0fake-jpeg";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.price_fetch.resolve_hosts' => false, 'services.price_fetch.browser_url' => null]);
        Http::preventStrayRequests();
    }

    public function test_photo_is_brought_from_the_store_page(): void
    {
        $costco = Provider::create(['name' => 'Costco', 'chain' => 'costco']);
        $product = Product::create(['name' => 'Aspiradora', 'size' => 1, 'unit' => 'item']);
        $product->links()->create(['provider_id' => $costco->id, 'url' => 'https://www.costco.com.mx/Electro/Dyson/p/694555']);
        Http::fake([
            'www.costco.com.mx/rest/*' => Http::response(['code' => '694555', 'images' => [
                ['format' => 'thumbnail', 'url' => '/medias/thumb.jpg'],
                ['format' => 'product', 'url' => '/medias/product.jpg'],
            ]]),
            'www.costco.com.mx/medias/product.jpg' => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->post(route('products.photo.fetch', $product))->assertRedirect()->assertSessionHas('success', 'Foto de Costco guardada.');

        $photo = $product->fresh()->coverPhoto();
        $this->assertNotNull($photo);
        Storage::disk('local')->assertExists($photo->path);
        $this->get($photo->url())->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(route('products.show', $product))->assertOk()->assertSee($photo->url(true), false);
    }

    public function test_without_store_page_it_uses_the_barcode_photo_and_reports_when_nothing_is_found(): void
    {
        $product = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l', 'barcode' => '7501020547047']);
        Http::fake([
            'world.openfoodfacts.org/*' => Http::response(['status' => 1, 'product' => [
                'product_name' => 'Leche entera', 'image_front_small_url' => 'https://images.openfoodfacts.org/images/products/750/front_es.3.200.jpg',
            ]]),
            '*.openfoodfacts.org/api/*' => Http::response(['status' => 0]),
            'images.openfoodfacts.org/*' => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response(['items' => []]),
        ]);

        $this->postJson(route('products.photo.fetch', $product))->assertOk()->assertJsonPath('ok', true);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'front_es.3.400.jpg'));

        $other = Product::create(['name' => 'Tortillas', 'size' => 1, 'unit' => 'kg']);
        $this->postJson(route('products.photo.fetch', $other))->assertStatus(422)->assertJsonPath('ok', false);
    }

    public function test_fetching_a_price_saves_the_store_photo_when_the_product_has_none(): void
    {
        Setting::put(Setting::POSTAL_CODE, null);
        $store = Provider::create(['name' => 'Tienda']);
        $product = Product::create(['name' => 'Café', 'size' => 1, 'unit' => 'kg']);
        $link = $product->links()->create(['provider_id' => $store->id, 'url' => 'https://tienda.example.com/cafe']);
        Http::fake([
            'tienda.example.com/cafe' => Http::response('<meta property="og:image" content="https://cdn.example.com/cafe.png">'
                .'<script type="application/ld+json">{"@type":"Product","name":"Café 1 kg","offers":{"price":"250"}}</script>'),
            'cdn.example.com/*' => Http::response("\x89PNG", 200, ['Content-Type' => 'image/png']),
        ]);

        $this->postJson(route('product-links.fetch', $link))->assertOk()->assertJsonPath('ok', true);
        $this->assertSame('image/png', $product->fresh()->coverPhoto()?->mime);

        // Ya tiene foto: no se vuelve a descargar
        $this->postJson(route('product-links.fetch', $link))->assertOk();
        $this->assertSame(1, $product->attachments()->count());
    }

    public function test_photos_are_uploaded_from_the_product_form(): void
    {
        $this->post(route('products.store'), [
            'name' => 'Arroz', 'size' => 1, 'unit' => 'kg',
            'files' => [UploadedFile::fake()->create('arroz.jpg', 50, 'image/jpeg')],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(Product::where('name', 'Arroz')->firstOrFail()->coverPhoto()?->isPhoto());
    }
}
