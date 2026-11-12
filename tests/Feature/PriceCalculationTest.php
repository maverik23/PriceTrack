<?php

namespace Tests\Feature;

use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PriceCalculationTest extends TestCase
{
    use RefreshDatabase;

    public static function presentations(): array
    {
        // precio, piezas, contenido, unidad, $ por unidad comparativa esperado, etiqueta
        return [
            'salsa 345 ml' => [20.70, 1, 345, 'ml', 60.00, '/L'],
            'leche 1 L' => [28.50, 1, 1, 'l', 28.50, '/L'],
            'leche costco 6 × 1 L' => [150.00, 6, 1, 'l', 25.00, '/L'],
            'pan 680 g' => [51.00, 1, 680, 'g', 75.00, '/kg'],
            'arroz 1 kg' => [34.00, 1, 1, 'kg', 34.00, '/kg'],
            'huevo 30 pzas' => [78.00, 1, 30, 'item', 2.60, '/pza'],
            'atún 6 × 140 g' => [126.00, 6, 140, 'g', 150.00, '/kg'],
        ];
    }

    #[DataProvider('presentations')]
    public function test_it_normalizes_price_per_unit(float $price, int $pack, float $size, string $unit, float $expected, string $label): void
    {
        $product = Product::create(['name' => 'X', 'size' => $size, 'unit' => $unit]);
        $provider = Provider::create(['name' => 'Walmart']);

        $p = Price::create([
            'product_id' => $product->id,
            'provider_id' => $provider->id,
            'price' => $price,
            'pack_qty' => $pack,
            'size' => $size,
            'unit' => $unit,
            'observed_on' => today(),
        ])->fresh();

        $this->assertEqualsWithDelta($expected, $p->comparePrice(), 0.01);
        $this->assertSame($label, $p->compareLabel());
    }

    public function test_cheapest_uses_latest_price_per_provider_and_compares_by_unit(): void
    {
        $product = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);
        $walmart = Provider::create(['name' => 'Walmart']);
        $costco = Provider::create(['name' => 'Costco']);
        $amazon = Provider::create(['name' => 'Amazon']);

        $make = fn ($provider, $price, $pack, $date) => Price::create([
            'product_id' => $product->id, 'provider_id' => $provider->id, 'price' => $price,
            'pack_qty' => $pack, 'size' => 1, 'unit' => 'l', 'observed_on' => $date,
        ]);

        $make($walmart, 20.00, 1, today()->subMonth()); // viejo y barato: ya no cuenta
        $make($walmart, 28.50, 1, today());              // 28.50 /L
        $make($costco, 162.00, 6, today()->subWeek());   // 27.00 /L (más caro por paquete, más barato por litro)
        $make($amazon, 29.90, 1, today());               // 29.90 /L

        $latest = $product->fresh()->latestPrices();

        $this->assertCount(3, $latest);
        $this->assertSame(['Costco', 'Walmart', 'Amazon'], $latest->map(fn ($p) => $p->provider->name)->all());
        $this->assertEqualsWithDelta(27.00, $product->fresh()->cheapestPrice()->comparePrice(), 0.001);
    }
}
