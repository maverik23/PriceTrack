<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('category', 60)->nullable()->index();
            $table->string('brand', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('serial_number', 80)->nullable();
            $table->string('location', 80)->nullable();
            $table->date('purchased_on')->nullable();
            $table->decimal('purchase_price', 14, 2)->nullable();
            $table->date('warranty_until')->nullable();
            $table->text('notes')->nullable();
            // active | sold | retired; al venderse o darse de baja: fecha y monto recibido
            $table->string('status', 10)->default('active')->index();
            $table->date('disposed_on')->nullable();
            $table->decimal('disposal_amount', 14, 2)->nullable();
            // Estimación del valor entre capturas: none | straight_line | annual_rate
            $table->string('valuation_method', 15)->default('none');
            $table->decimal('useful_life_years', 5, 2)->nullable();
            $table->decimal('salvage_value', 14, 2)->nullable();
            // % anual: negativo = se deprecia, positivo = plusvalía
            $table->decimal('annual_rate', 6, 2)->nullable();
            // Medidor de uso opcional: km, h…
            $table->string('meter_unit', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('asset_valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->date('valued_on');
            $table->decimal('value', 14, 2);
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['asset_id', 'valued_on']);
        });

        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->date('read_on');
            $table->decimal('reading', 12, 1);
            $table->timestamps();
            $table->index(['asset_id', 'read_on']);
        });

        Schema::create('maintenance_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            // Por tiempo (cada N días/semanas/meses/años) y/o por uso (cada N km, h)
            $table->unsignedSmallInteger('every')->nullable();
            $table->string('every_unit', 10)->nullable();
            $table->decimal('every_meter', 12, 1)->nullable();
            $table->decimal('estimated_cost', 12, 2)->nullable();
            $table->string('notes')->nullable();
            // Tarea pendiente que recuerda el próximo servicio
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('maintenance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('maintenance_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->date('done_on')->index();
            $table->decimal('meter_reading', 12, 1)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('provider', 100)->nullable();
            $table->string('notes')->nullable();
            // Si se registró al completar la tarea del plan (para deshacerlo si se reabre)
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_logs');
        Schema::dropIfExists('maintenance_plans');
        Schema::dropIfExists('meter_readings');
        Schema::dropIfExists('asset_valuations');
        Schema::dropIfExists('assets');
    }
};
