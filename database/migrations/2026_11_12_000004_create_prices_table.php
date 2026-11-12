<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            // Lista desde la que se capturó el precio (si aplica)
            $table->foreignId('shopping_list_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('price', 10, 2);              // precio pagado por el paquete
            $table->unsignedInteger('pack_qty')->default(1); // ej. 6 en "6 × 1 L"
            $table->decimal('size', 10, 3);               // contenido de cada pieza
            $table->string('unit', 10);                   // ml, l, g, kg, item

            // Calculados al guardar
            $table->decimal('base_qty', 14, 3);           // total en ml / g / pza
            $table->decimal('unit_price', 16, 6);         // $ por ml / g / pza

            $table->date('observed_on');                  // fecha en que se registró el precio
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'provider_id', 'observed_on']);
            $table->index('observed_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
