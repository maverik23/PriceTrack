<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Antes, sin tienda Chedraui elegida se usaba la más cercana. Ahora "sin elegir" hace que la
 * app la pregunte; a quien ya tenía código postal se le respeta lo que tenía: automática.
 */
return new class extends Migration
{
    public function up(): void
    {
        $postalCode = DB::table('settings')->where('key', 'postal_code')->value('value');
        $store = DB::table('settings')->where('key', 'chedraui_store')->value('value');

        if ($postalCode && ! $store) {
            DB::table('settings')->updateOrInsert(['key' => 'chedraui_store'], ['value' => 'auto', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'chedraui_store')->where('value', 'auto')->update(['value' => null]);
    }
};
