<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            // Lista ya comprada (se tacha y deja de verse entre las pendientes)
            $table->timestamp('completed_at')->nullable()->after('notes');
        });

        // Las que ya tienen todo comprado cuentan como compradas
        DB::table('shopping_lists')
            ->whereExists(fn ($q) => $q->from('shopping_list_items')->whereColumn('shopping_list_items.shopping_list_id', 'shopping_lists.id'))
            ->whereNotExists(fn ($q) => $q->from('shopping_list_items')->whereColumn('shopping_list_items.shopping_list_id', 'shopping_lists.id')->whereNull('price_id'))
            ->update(['completed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
