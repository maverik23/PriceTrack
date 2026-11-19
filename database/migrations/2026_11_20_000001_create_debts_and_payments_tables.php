<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('lender', 80)->nullable();
            // mortgage | car | card | personal | other
            $table->string('type', 12)->default('other');
            $table->decimal('original_amount', 16, 2);
            // % anual (ej. 10.5); null = sin intereses
            $table->decimal('annual_rate', 6, 3)->nullable();
            $table->date('started_on')->nullable();
            $table->date('paid_off_on')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('debt_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->date('balance_on');
            $table->decimal('balance', 16, 2);
            $table->timestamps();
            $table->index(['debt_id', 'balance_on']);
        });

        Schema::create('recurring_payments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('category', 60)->nullable()->index();
            $table->decimal('amount', 14, 2);
            $table->unsignedSmallInteger('every')->default(1);
            $table->string('every_unit', 10)->default('month');
            $table->date('next_due_on')->index();
            // La mensualidad de una deuda: al pagarla baja su saldo
            $table->foreignId('debt_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('debt_id')->nullable()->constrained()->nullOnDelete();
            $table->date('paid_on')->index();
            $table->decimal('amount', 14, 2);
            // Fecha que tocaba (para deshacer y regresar la próxima fecha)
            $table->date('due_on')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('recurring_payments');
        Schema::dropIfExists('debt_balances');
        Schema::dropIfExists('debts');
    }
};
