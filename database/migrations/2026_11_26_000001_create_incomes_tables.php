<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ingresos: recurrentes (sueldo, renta, vales cada mes) y lo que se recibe (de una recurrente
 * o eventual: un bono, una venta, un regalo). En dinero o en especie (vales, regalos), con su valor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('category', 60)->nullable();
            // money | in_kind
            $table->string('kind', 10)->default('money');
            $table->decimal('amount', 14, 2);
            $table->unsignedSmallInteger('every')->default(1);
            // day | week | fortnight (quincena: el 15 y el último día) | month | year
            $table->string('every_unit', 10)->default('month');
            $table->date('next_on');
            $table->boolean('active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('income_source_id')->nullable()->constrained()->nullOnDelete();
            $table->date('received_on')->index();
            // La fecha que tocaba, si vino de una recurrente
            $table->date('due_on')->nullable();
            $table->string('name', 120);
            $table->string('category', 60)->nullable();
            $table->string('kind', 10)->default('money');
            $table->decimal('amount', 14, 2);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incomes');
        Schema::dropIfExists('income_sources');
    }
};
