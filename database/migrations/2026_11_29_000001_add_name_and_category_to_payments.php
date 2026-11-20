<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos únicos (gasolina, una comida fuera): un pago sin pago recurrente ni deuda, con su
 * concepto y categoría. Los de un recurrente guardan también su nombre y categoría al pagarse,
 * como los ingresos, para no perderlos si el recurrente se borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('name', 120)->nullable()->after('debt_id');
            $table->string('category', 60)->nullable()->index()->after('name');
        });

        DB::table('recurring_payments')->orderBy('id')->each(function (object $recurring) {
            DB::table('payments')->where('recurring_payment_id', $recurring->id)
                ->update(['name' => $recurring->name, 'category' => $recurring->category]);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn(['name', 'category']);
        });
    }
};
