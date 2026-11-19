<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('institution', 80)->nullable();
            // cetes | fund | stocks | crypto | savings | retirement | other
            $table->string('type', 15)->default('other');
            $table->char('currency', 3)->default('MXN');
            $table->string('notes')->nullable();
            // Cerrada: vale 0 desde esta fecha
            $table->date('closed_on')->nullable();
            $table->timestamps();
        });

        Schema::create('investment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_account_id')->constrained()->cascadeOnDelete();
            $table->date('occurred_on')->index();
            // deposit | withdrawal | income (dividendo o interés) | fee (comisión)
            $table->string('kind', 12);
            // Positivo, en la moneda de la cuenta
            $table->decimal('amount', 16, 2);
            // Pesos por unidad de la moneda de la cuenta (1 si es MXN)
            $table->decimal('exchange_rate', 14, 6)->default(1);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('investment_valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_account_id')->constrained()->cascadeOnDelete();
            $table->date('valued_on');
            $table->decimal('value', 16, 2);
            $table->decimal('exchange_rate', 14, 6)->default(1);
            $table->timestamps();
            $table->index(['investment_account_id', 'valued_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_valuations');
        Schema::dropIfExists('investment_transactions');
        Schema::dropIfExists('investment_accounts');
    }
};
