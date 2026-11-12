<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductFormTest extends TestCase
{
    use RefreshDatabase;

    private function store(array $data)
    {
        return $this->post(route('products.store'), $data + ['name' => 'X', 'size' => 1, 'unit' => 'item']);
    }

    public function test_existing_brand_and_category_are_reused_even_if_typed_differently(): void
    {
        Product::create(['name' => 'Leche', 'brand' => 'Lala', 'category' => 'Lácteos', 'size' => 1, 'unit' => 'l']);

        $this->store(['name' => 'Queso', 'brand' => '  lala ', 'category' => 'LACTEOS'])->assertSessionHasNoErrors();

        $queso = Product::where('name', 'Queso')->firstOrFail();
        $this->assertSame('Lala', $queso->brand);
        $this->assertSame('Lácteos', $queso->category);
        $this->assertSame(['Lácteos'], Product::distinctValues('category')->all());
    }

    public function test_new_brand_and_category_are_saved_as_new_values(): void
    {
        Product::create(['name' => 'Leche', 'brand' => 'Lala', 'category' => 'Lácteos', 'size' => 1, 'unit' => 'l']);

        $this->store(['name' => 'Pan', 'brand' => 'Bimbo', 'category' => 'Panadería   dulce'])->assertSessionHasNoErrors();

        $pan = Product::where('name', 'Pan')->firstOrFail();
        $this->assertSame('Bimbo', $pan->brand);
        $this->assertSame('Panadería dulce', $pan->category);
        $this->assertSame(['Bimbo', 'Lala'], Product::distinctValues('brand')->all());
    }

    public function test_empty_brand_and_category_stay_null(): void
    {
        $this->store(['name' => 'Plátano', 'brand' => '', 'category' => '   '])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['name' => 'Plátano', 'brand' => null, 'category' => null]);
    }

    public function test_forms_offer_existing_brands_and_categories(): void
    {
        $product = Product::create(['name' => 'Leche', 'brand' => 'Lala', 'category' => 'Lácteos', 'size' => 1, 'unit' => 'l']);

        foreach ([route('products.create'), route('products.edit', $product)] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertSame(['Lala'], $response->viewData('brands')->all());
            $this->assertSame(['Lácteos'], $response->viewData('categories')->all());
            $response->assertSee('x-data="combobox(', false);
        }
    }
}
