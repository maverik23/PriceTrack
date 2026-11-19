<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Debt;
use App\Models\InvestmentAccount;
use App\Models\MealPlanEntry;
use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\RecurringPayment;
use App\Models\ShoppingList;
use App\Models\Task;
use App\Models\TaskList;
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

            $this->seedKitchen($products->keyBy('name'));
            $this->seedTasks();
            $this->seedAssets();
            $this->seedInvestments();
            $this->seedDebtsAndPayments();
        });
    }

    /** Hipoteca y crédito del auto con sus mensualidades pagadas, y servicios del hogar. */
    private function seedDebtsAndPayments(): void
    {
        if (Debt::exists() || RecurringPayment::exists()) {
            return;
        }
        $today = CarbonImmutable::today();

        // nombre, acreedor, tipo, monto, tasa, inicio, mensualidad
        $debts = [
            ['Hipoteca', 'Infonavit', 'mortgage', 1800000, 10.5, $today->subYears(7)->startOfMonth(), 17971],
            ['Crédito del auto', 'Mazda Finance', 'car', 250000, 13.9, $today->subYears(2)->subMonths(2)->startOfMonth(), 8530],
        ];
        foreach ($debts as [$name, $lender, $type, $amount, $rate, $start, $monthly]) {
            $debt = Debt::create(['name' => $name, 'lender' => $lender, 'type' => $type, 'original_amount' => $amount, 'annual_rate' => $rate, 'started_on' => $start]);
            $recurring = RecurringPayment::create([
                'name' => 'Mensualidad '.mb_strtolower($name), 'category' => 'Deudas', 'amount' => $monthly,
                'next_due_on' => $start->addMonth()->addDays(9), 'debt_id' => $debt->id,
            ]);
            while ($recurring->next_due_on->lte($today)) {
                $recurring->markPaid($recurring->next_due_on->copy()->subDays(mt_rand(0, 3)));
            }
            $debt->load('balances', 'payments');
            $debt->balances()->create(['balance_on' => $today->subMonths(2)->startOfMonth(), 'balance' => round($debt->balanceOn($today->subMonths(2)->startOfMonth()) * 1.002, 2)]);
        }

        // nombre, categoría, monto, cada, unidad, próximo pago
        $bills = [
            ['Luz (CFE)', 'Servicios', 640, 2, 'month', $today->addDays(5)],
            ['Agua', 'Servicios', 280, 2, 'month', $today->subDays(2)],
            ['Internet', 'Servicios', 499, 1, 'month', $today->addDays(3)],
            ['Gas', 'Servicios', 520, 1, 'month', $today->addDays(16)],
            ['Netflix', 'Suscripciones', 299, 1, 'month', $today->addDays(11)],
            ['Spotify', 'Suscripciones', 129, 1, 'month', $today->addDays(22)],
            ['Predial', 'Vivienda', 3800, 1, 'year', $today->addMonths(2)->startOfMonth()],
            ['Seguro del auto', 'Seguros', 14200, 1, 'year', $today->addMonths(4)],
        ];
        foreach ($bills as [$name, $category, $amount, $every, $unit, $next]) {
            $bill = RecurringPayment::create(['name' => $name, 'category' => $category, 'amount' => $amount, 'every' => $every, 'every_unit' => $unit, 'next_due_on' => $next]);
            // Unos pagos pasados para el historial
            $due = $bill->advance($next, -3);
            foreach (range(1, 3) as $_) {
                $bill->payments()->create(['paid_on' => $due, 'due_on' => $due, 'amount' => round($amount * mt_rand(92, 108) / 100, 2)]);
                $due = $bill->advance($due);
            }
        }
    }

    /** Cuentas de inversión con aportaciones mensuales y valores capturados cada mes o cada trimestre. */
    private function seedInvestments(): void
    {
        if (InvestmentAccount::exists()) {
            return;
        }
        $today = CarbonImmutable::today();
        $start = $today->subMonths(30)->startOfMonth();

        // nombre, institución, tipo, moneda, aportación mensual, rendimiento anual, cada cuántos meses se captura el valor
        $accounts = [
            ['CETES', 'Cetesdirecto', 'cetes', 'MXN', 3000, 0.105, 1],
            ['Afore', 'Afore XXI Banorte', 'retirement', 'MXN', 1500, 0.08, 3],
            ['ETF S&P 500', 'GBM', 'stocks', 'USD', 100, 0.14, 1],
        ];
        foreach ($accounts as [$name, $institution, $type, $currency, $monthly, $rate, $every]) {
            $account = InvestmentAccount::create(['name' => $name, 'institution' => $institution, 'type' => $type, 'currency' => $currency]);
            $balance = 0;
            for ($month = $start; $month->lte($today); $month = $month->addMonth()) {
                $fx = $currency === 'USD' ? round(17.5 + 2.5 * sin($month->month / 2) + $start->diffInMonths($month) * 0.03, 4) : 1;
                $balance = $balance * (1 + $rate / 12) * ($type === 'stocks' ? 1 + mt_rand(-40, 40) / 1000 : 1) + $monthly;
                $account->transactions()->create(['occurred_on' => $month->addDays(2)->min($today), 'kind' => 'deposit', 'amount' => $monthly, 'exchange_rate' => $fx]);
                if ($start->diffInMonths($month) % $every === 0 && $month->addDays(27)->lte($today)) {
                    $account->valuations()->create(['valued_on' => $month->addDays(27), 'value' => round($balance, 2), 'exchange_rate' => $fx]);
                }
            }
            if ($type === 'cetes') {
                $account->transactions()->create(['occurred_on' => $today->subMonths(4), 'kind' => 'withdrawal', 'amount' => 8000, 'notes' => 'Vacaciones']);
            }
        }

        // Cerrada: un pagaré que ya se cobró
        $pagare = InvestmentAccount::create(['name' => 'Pagaré 1 año', 'institution' => 'Nu', 'type' => 'savings', 'closed_on' => $today->subMonths(6)]);
        $pagare->transactions()->create(['occurred_on' => $today->subMonths(18), 'kind' => 'deposit', 'amount' => 20000]);
        $pagare->transactions()->create(['occurred_on' => $today->subMonths(6), 'kind' => 'withdrawal', 'amount' => 22100]);
    }

    /** Bienes con distintos métodos de valor, medidor, planes de mantenimiento y servicios. */
    private function seedAssets(): void
    {
        if (Asset::exists()) {
            return;
        }
        $today = CarbonImmutable::today();

        $auto = Asset::create([
            'name' => 'Auto', 'category' => 'Vehículos', 'brand' => 'Mazda', 'model' => '3 Sedán 2023', 'location' => 'Cochera',
            'purchased_on' => $today->subYears(3)->subMonths(2), 'purchase_price' => 380000,
            'valuation_method' => 'annual_rate', 'annual_rate' => -12, 'meter_unit' => 'km',
        ]);
        $auto->valuations()->create(['valued_on' => $today->subMonths(8), 'value' => 285000, 'notes' => 'Libro Azul']);
        foreach ([[38, 0], [26, 9800], [14, 21500], [2, 33200], [0, 34900]] as [$months, $km]) {
            $auto->meterReadings()->create(['read_on' => $today->subYears(3)->subMonths(2)->addMonths(38 - $months), 'reading' => $km]);
        }
        $afinacion = $auto->maintenancePlans()->create(['name' => 'Afinación', 'every' => 1, 'every_unit' => 'year', 'every_meter' => 15000, 'estimated_cost' => 3200]);
        $aceite = $auto->maintenancePlans()->create(['name' => 'Cambio de aceite', 'every' => 6, 'every_unit' => 'month', 'every_meter' => 7500, 'estimated_cost' => 1400]);
        $auto->maintenanceLogs()->create(['maintenance_plan_id' => $afinacion->id, 'done_on' => $today->subMonths(13), 'meter_reading' => 20500, 'cost' => 3050, 'provider' => 'Agencia']);
        $auto->maintenanceLogs()->create(['maintenance_plan_id' => $aceite->id, 'done_on' => $today->subMonths(5), 'meter_reading' => 28900, 'cost' => 1350, 'provider' => 'Taller Gómez']);
        $auto->maintenanceLogs()->create(['done_on' => $today->subMonths(10), 'cost' => 4800, 'provider' => 'Llantera', 'notes' => 'Dos llantas']);

        $casa = Asset::create([
            'name' => 'Casa', 'category' => 'Inmuebles', 'location' => 'Coyoacán',
            'purchased_on' => $today->subYears(7), 'purchase_price' => 2400000,
            'valuation_method' => 'annual_rate', 'annual_rate' => 6,
        ]);
        $casa->valuations()->create(['valued_on' => $today->subYears(2), 'value' => 3150000, 'notes' => 'Avalúo bancario']);
        $impermeabilizar = $casa->maintenancePlans()->create(['name' => 'Impermeabilizar', 'every' => 3, 'every_unit' => 'year', 'estimated_cost' => 18000]);
        $casa->maintenanceLogs()->create(['maintenance_plan_id' => $impermeabilizar->id, 'done_on' => $today->subYears(3)->addWeeks(2), 'cost' => 16500]);

        $refri = Asset::create([
            'name' => 'Refrigerador', 'category' => 'Electrodomésticos', 'brand' => 'LG', 'location' => 'Cocina',
            'purchased_on' => $today->subYears(1)->addDays(40), 'purchase_price' => 21999, 'warranty_until' => $today->addDays(40),
            'valuation_method' => 'straight_line', 'useful_life_years' => 10, 'salvage_value' => 1500,
        ]);
        $refri->maintenancePlans()->create(['name' => 'Limpiar el condensador', 'every' => 6, 'every_unit' => 'month', 'estimated_cost' => 0]);

        $minisplit = Asset::create([
            'name' => 'Minisplit recámara', 'category' => 'Electrodomésticos', 'location' => 'Recámara',
            'purchased_on' => $today->subYears(2), 'purchase_price' => 12500, 'warranty_until' => $today->subMonths(2),
            'valuation_method' => 'straight_line', 'useful_life_years' => 8,
        ]);
        $filtros = $minisplit->maintenancePlans()->create(['name' => 'Lavar filtros', 'every' => 3, 'every_unit' => 'month']);
        $minisplit->maintenanceLogs()->create(['maintenance_plan_id' => $filtros->id, 'done_on' => $today->subMonths(4)]);

        Asset::create([
            'name' => 'Laptop', 'category' => 'Electrónica', 'purchased_on' => $today->subYears(4), 'purchase_price' => 24000,
            'status' => 'sold', 'disposed_on' => $today->subMonths(3), 'disposal_amount' => 7000,
            'valuation_method' => 'straight_line', 'useful_life_years' => 5,
        ]);

        Asset::all()->each->syncMaintenanceTasks();
    }

    /** Listas de tareas con pendientes vencidos, de hoy, próximos y repetitivos. */
    private function seedTasks(): void
    {
        if (Task::exists()) {
            return;
        }
        $today = CarbonImmutable::today();
        $casa = TaskList::firstOrCreate(['name' => 'Casa']);
        $auto = TaskList::firstOrCreate(['name' => 'Auto']);

        $tasks = [
            [$casa, 'Pagar la luz', -3, 3, 2, 'month'],
            [$casa, 'Regar las plantas', 0, 2, 3, 'day'],
            [$casa, 'Cambiar filtro del purificador', 12, 2, 6, 'month'],
            [$casa, 'Limpiar el refrigerador', 4, 1, null, null],
            [$auto, 'Verificación', 20, 3, 6, 'month'],
            [$auto, 'Revisar presión de llantas', -1, 2, 2, 'week'],
            [null, 'Renovar pasaporte', null, 2, null, null],
            [null, 'Comprar foco del baño', 0, 2, null, null],
        ];
        foreach ($tasks as [$list, $title, $days, $priority, $every, $unit]) {
            Task::create([
                'task_list_id' => $list?->id, 'title' => $title, 'priority' => $priority,
                'due_on' => $days === null ? null : $today->addDays($days),
                'repeat_every' => $every, 'repeat_unit' => $unit,
            ]);
        }
        Task::create(['task_list_id' => $casa->id, 'title' => 'Sacar la basura', 'priority' => 2,
            'due_on' => $today->subDay(), 'repeat_every' => 3, 'repeat_unit' => 'day'])->complete($today->subDay()->setTime(21, 0));
    }

    /** Recetas con artículos del catálogo y el menú de esta semana. */
    private function seedKitchen($products): void
    {
        // Para recetas: peso de una pieza / de una taza y nutrición por 100 g/ml (por pieza en el huevo)
        $kitchen = [
            'Arroz 1 kg' => ['cup_grams' => 200, 'kcal' => 360, 'protein' => 7, 'carbs' => 79, 'fat' => 0.6],
            'Frijol negro 900 g' => ['cup_grams' => 190, 'kcal' => 341, 'protein' => 21.6, 'carbs' => 62.4, 'fat' => 1.4],
            'Huevo blanco 30 pzas' => ['piece_weight' => 50, 'kcal' => 72, 'protein' => 6.3, 'carbs' => 0.4, 'fat' => 4.8],
            'Aceite vegetal 946 ml' => ['cup_grams' => 218, 'kcal' => 828, 'protein' => 0, 'carbs' => 0, 'fat' => 92],
            'Salsa de tomate 345 ml' => ['cup_grams' => 245, 'kcal' => 29, 'protein' => 1.3, 'carbs' => 5.5, 'fat' => 0.2],
            'Tortilla de maíz' => ['piece_weight' => 30, 'kcal' => 218, 'protein' => 5.7, 'carbs' => 44.6, 'fat' => 2.9],
            'Pan blanco grande' => ['piece_weight' => 28, 'kcal' => 265, 'protein' => 9, 'carbs' => 49, 'fat' => 3.2],
            'Queso panela 400 g' => ['cup_grams' => 120, 'kcal' => 250, 'protein' => 18, 'carbs' => 3, 'fat' => 18],
            'Leche entera 1 L' => ['cup_grams' => 248, 'kcal' => 61, 'protein' => 3.2, 'carbs' => 4.8, 'fat' => 3.3],
            'Plátano' => ['piece_weight' => 120, 'kcal' => 89, 'protein' => 1.1, 'carbs' => 22.8, 'fat' => 0.3],
        ];
        foreach ($kitchen as $name => $data) {
            $products->get($name)?->update($data);
        }

        $recipes = [
            ['Arroz rojo', 'Guarniciones', 4, 35, "Lava y escurre el arroz.\nFríelo en el aceite hasta que se dore.\nLicúa el jitomate con ajo y cebolla, agrégalo y deja que se seque.\nAñade agua caliente y sal, tapa y cocina a fuego bajo 20 minutos.", [
                ['Arroz 1 kg', 1.25, 'cup', null],
                ['Aceite vegetal 946 ml', 2, 'tbsp', null],
                ['Salsa de tomate 345 ml', 200, 'ml', null],
                ['Sal', null, null, 'al gusto'],
            ]],
            ['Frijoles de la olla', 'Guisados', 6, 150, "Remoja los frijoles la noche anterior.\nCuécelos con cebolla y ajo hasta que estén suaves.\nSala al final.", [
                ['Frijol negro 900 g', 2.5, 'cup', null],
                ['Cebolla', 0.5, null, 'en trozo'],
                ['Sal', null, null, 'al gusto'],
            ]],
            ['Huevos a la mexicana', 'Desayunos', 2, 15, "Pica jitomate, cebolla y chile.\nSofríe en aceite.\nAgrega los huevos batidos y revuelve hasta que cuajen.\nSirve con tortillas.", [
                ['Huevo blanco 30 pzas', 4, 'item', null],
                ['Aceite vegetal 946 ml', 1, 'tbsp', null],
                ['Tortilla de maíz', 8, 'item', 'calientes'],
                ['Jitomate, cebolla y chile serrano', null, null, 'picados'],
            ]],
            ['Molletes', 'Desayunos', 4, 20, "Parte el pan a la mitad.\nUnta frijoles y cubre con queso.\nHornea hasta que gratine.", [
                ['Pan blanco grande', 8, 'item', 'rebanadas'],
                ['Frijol negro 900 g', 1, 'cup', 'refritos'],
                ['Queso panela 400 g', 1.5, 'cup', 'rallado'],
            ]],
        ];

        $created = [];
        foreach ($recipes as [$name, $category, $servings, $minutes, $instructions, $ingredients]) {
            $recipe = Recipe::firstOrCreate(['name' => $name], [
                'category' => $category, 'servings' => $servings, 'minutes' => $minutes, 'instructions' => $instructions,
            ]);
            if (! $recipe->wasRecentlyCreated) {
                $created[$name] = $recipe;

                continue;
            }
            foreach ($ingredients as $position => [$ingredient, $quantity, $unit, $note]) {
                $product = $products->get($ingredient);
                $recipe->ingredients()->create([
                    'product_id' => $product?->id,
                    'name' => $ingredient,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'note' => $note,
                    'position' => $position,
                ]);
            }
            $created[$name] = $recipe;
        }

        // Menú de la semana en curso
        $monday = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY);
        $plan = [
            [0, 'lunch', 'Arroz rojo', null], [0, 'dinner', 'Molletes', null],
            [1, 'breakfast', 'Huevos a la mexicana', null], [1, 'lunch', 'Frijoles de la olla', null],
            [2, 'lunch', null, 'Sobras'], [3, 'breakfast', 'Molletes', null],
            [4, 'lunch', 'Arroz rojo', null], [5, 'lunch', null, 'Comer fuera'],
            [6, 'breakfast', 'Huevos a la mexicana', 4],
        ];
        foreach ($plan as [$day, $meal, $recipe, $extra]) {
            MealPlanEntry::firstOrCreate(
                ['planned_on' => $monday->addDays($day)->toDateString(), 'meal' => $meal],
                $recipe
                    ? ['recipe_id' => $created[$recipe]->id, 'servings' => $extra]
                    : ['title' => $extra],
            );
        }
    }
}
