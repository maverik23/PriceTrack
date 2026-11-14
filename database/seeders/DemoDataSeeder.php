<?php

namespace Database\Seeders;

use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ShoppingList;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Un año de listas semanales con precios en varios proveedores,
 * con inflación distinta por artículo, presentaciones de club (Sam's/Costco)
 * y alguna oferta ocasional. Determinista (misma semilla = mismos datos).
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(2026);

        $this->call(DatabaseSeeder::class);
        $providers = Provider::pluck('id', 'name');

        // nombre, marca, categoría, contenido, unidad, precio Walmart, inflación anual, piezas en club
        $catalog = [
            ['Salsa de tomate 345 ml', 'Del Monte', 'Abarrotes', 345, 'ml', 24.50, 0.06, 3],
            ['Pan blanco grande', 'Bimbo', 'Panadería', 680, 'g', 52.00, 0.09, 2],
            ['Leche entera 1 L', 'Lala', 'Lácteos', 1, 'l', 28.50, 0.07, 6],
            ['Huevo blanco 30 pzas', null, 'Abarrotes', 30, 'item', 78.00, 0.14, 2],
            ['Arroz 1 kg', 'Verde Valle', 'Abarrotes', 1, 'kg', 34.00, 0.03, 4],
            ['Aceite vegetal 946 ml', 'Nutrioli', 'Abarrotes', 946, 'ml', 49.90, -0.03, 3],
            ['Papel higiénico 12 rollos', 'Pétalo', 'Limpieza', 12, 'item', 98.00, 0.05, 3],
            ['Café soluble 225 g', 'Nescafé', 'Abarrotes', 225, 'g', 139.00, 0.11, 2],
            ['Atún en agua 140 g', 'Dolores', 'Abarrotes', 140, 'g', 22.90, 0.04, 6],
            ['Frijol negro 900 g', 'Verde Valle', 'Abarrotes', 900, 'g', 41.50, 0.02, 3],
            ['Detergente líquido 5 L', 'Ariel', 'Limpieza', 5, 'l', 229.00, 0.06, 1],
            ['Jabón para trastes 750 ml', 'Salvo', 'Limpieza', 750, 'ml', 42.00, 0.05, 2],
            ['Queso panela 400 g', 'Lala', 'Lácteos', 400, 'g', 72.00, 0.08, 2],
            ['Plátano', null, 'Frutas y verduras', 1, 'kg', 26.00, 0.10, 1],
            ['Tortilla de maíz', null, 'Panadería', 1, 'kg', 24.00, 0.12, 1],
        ];

        // Factor de precio por unidad respecto a Walmart
        $providerFactor = ["Sam's Club" => 0.89, 'Costco' => 0.87, 'Walmart' => 1.00, 'Amazon' => 1.03];
        $staples = [2, 3, 1, 14]; // leche, huevo, pan, tortilla

        DB::transaction(function () use ($catalog, $providers, $providerFactor, $staples) {
            $products = collect($catalog)->map(fn ($row) => Product::firstOrCreate(
                ['name' => $row[0]],
                ['brand' => $row[1], 'category' => $row[2], 'size' => $row[3], 'unit' => $row[4]]
            ))->values();

            $today = CarbonImmutable::today();
            $start = $today->subWeeks(52)->next(CarbonImmutable::SATURDAY);

            for ($date = $start; $date->lessThanOrEqualTo($today); $date = $date->addWeek()) {
                $isLast = $date->addWeek()->greaterThan($today);
                $listDate = $isLast ? $today : $date;

                $list = ShoppingList::create([
                    'name' => 'Despensa '.$listDate->translatedFormat('d M Y'),
                    'shopped_on' => $listDate,
                ]);

                $others = collect(range(0, count($catalog) - 1))->diff($staples)->shuffle()->take(mt_rand(3, 6));
                $chosen = collect($staples)->merge($others);

                foreach ($chosen as $idx) {
                    [$name, , , $size, $unit, $base, $rate, $clubPack] = $catalog[$idx];
                    $product = $products[$idx];
                    // Fruta y tortilla se pesan: precio por kg y la cantidad que quieras
                    $bulk = in_array($name, ['Plátano', 'Tortilla de maíz'], true);
                    $years = $start->diffInDays($listDate) / 365;
                    // Precio "escalonado" por mes para que no cambie cada semana
                    $monthStep = floor($years * 12) / 12;

                    $seen = collect(['Walmart'])
                        ->merge(collect(["Sam's Club", 'Costco', 'Amazon'])->shuffle()->take(mt_rand(1, 2)));

                    $prices = $seen->map(function ($providerName) use ($product, $providers, $providerFactor, $base, $rate, $monthStep, $size, $unit, $clubPack, $listDate, $list, $bulk) {
                        $isClub = in_array($providerName, ["Sam's Club", 'Costco']);
                        $pack = $isClub ? $clubPack : 1;
                        $unitFactor = $providerFactor[$providerName] * (1 + $rate * $monthStep);
                        // Oferta ocasional
                        if (mt_rand(1, 100) <= 8) {
                            $unitFactor *= 0.85;
                        }
                        $price = round($base * $pack * $unitFactor, 1) - 0.1;

                        return Price::create([
                            'product_id' => $product->id,
                            'provider_id' => $providers[$providerName],
                            'shopping_list_id' => $list->id,
                            'price' => max($price, 1),
                            'pack_qty' => $pack,
                            'size' => $size,
                            'unit' => $unit,
                            'bulk' => $bulk,
                            'observed_on' => $listDate,
                        ]);
                    });

                    // Normalmente compra el más barato; a veces Walmart por comodidad
                    $buy = mt_rand(1, 100) <= 75
                        ? $prices->sortBy('unit_price')->first()
                        : $prices->first();

                    // En la lista de hoy deja la mitad pendiente para ver el flujo
                    $purchased = ! $isLast || mt_rand(0, 1) === 1;

                    // Cantidad = lo que se necesita en presentaciones habituales (un paquete de club cubre varias)
                    $list->items()->create([
                        'product_id' => $product->id,
                        'quantity' => match (true) {
                            $bulk => mt_rand(5, 20) / 10,
                            $buy->pack_qty > 1 => $buy->pack_qty,
                            default => mt_rand(1, 2),
                        },
                        'price_id' => $purchased ? $buy->id : null,
                    ]);
                }
            }
        });
    }
}
