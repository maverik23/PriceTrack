<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Para recetas: cuánto pesa (g) o mide (ml) una pieza ("1 cebolla"), y cuánto pesa una taza
            $table->decimal('piece_weight', 10, 2)->nullable()->after('unit');
            $table->decimal('cup_grams', 8, 2)->nullable()->after('piece_weight');
            // Por 100 g o 100 ml (por pieza si el artículo se mide en piezas)
            $table->decimal('kcal', 8, 2)->nullable()->after('cup_grams');
            $table->decimal('protein', 8, 2)->nullable()->after('kcal');
            $table->decimal('carbs', 8, 2)->nullable()->after('protein');
            $table->decimal('fat', 8, 2)->nullable()->after('carbs');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['piece_weight', 'cup_grams', 'kcal', 'protein', 'carbs', 'fat']);
        });
    }
};
