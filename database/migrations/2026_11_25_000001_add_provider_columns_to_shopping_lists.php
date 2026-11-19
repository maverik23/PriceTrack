<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            // Tiendas en la hoja: por defecto solo las que tienen datos; {"added": [ids], "hidden": [ids]}
            $table->json('provider_columns')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->dropColumn('provider_columns');
        });
    }
};
