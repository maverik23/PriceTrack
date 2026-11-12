<?php

namespace Database\Seeders;

use App\Models\Provider;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Base limpia: solo proveedores.
     * Para datos de ejemplo: sail artisan db:seed --class=DemoDataSeeder
     */
    public function run(): void
    {
        foreach (["Sam's Club", 'Costco', 'Walmart', 'Amazon'] as $name) {
            Provider::firstOrCreate(['name' => $name]);
        }
    }
}
