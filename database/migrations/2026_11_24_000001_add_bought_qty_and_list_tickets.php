<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_list_items', function (Blueprint $table) {
            // Lo que se compró, en unidades del precio elegido: paquetes (3 × 900 g) o, a granel,
            // múltiplos de su contenido (1.2 de "por kg"). Null = los paquetes que cubren lo pedido.
            $table->decimal('bought_qty', 12, 4)->nullable()->after('price_id');
        });

        // Total del ticket de cada tienda en una lista: lo que de verdad se pagó (con descuentos)
        Schema::create('list_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->decimal('total', 12, 2);
            $table->timestamps();
            $table->unique(['shopping_list_id', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_tickets');
        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->dropColumn('bought_qty');
        });
    }
};
