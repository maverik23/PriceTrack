<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lectura del medidor en cada recibo (agua en m³, luz en kWh…) para sacar consumo y precio por unidad. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_payments', function (Blueprint $table) {
            // Unidad de la lectura ("m³", "kWh"); vacío = no se lleva lectura
            $table->string('meter_unit', 12)->nullable()->after('amount');
        });
        Schema::table('payments', function (Blueprint $table) {
            // Lectura final del periodo que se paga
            $table->decimal('reading', 14, 3)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('reading'));
        Schema::table('recurring_payments', fn (Blueprint $table) => $table->dropColumn('meter_unit'));
    }
};
