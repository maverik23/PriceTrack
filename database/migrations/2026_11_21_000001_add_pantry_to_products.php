<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Despensa: null = no se lleva en casa, stocked = lo tengo, out = se acabó (comprarlo lo vuelve a marcar)
            $table->string('pantry', 10)->nullable()->after('fat')->index();
            // Cuánto queda, en la unidad base del artículo (g, ml, pza); null = suficiente
            $table->decimal('pantry_qty', 12, 3)->nullable()->after('pantry');
            $table->timestamp('pantry_updated_at')->nullable()->after('pantry_qty');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['pantry']);
            $table->dropColumn(['pantry', 'pantry_qty', 'pantry_updated_at']);
        });
    }
};
