<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 8, 2)->default(1);
            // Precio elegido (dónde se compró). Null = aún no comprado.
            $table->foreignId('price_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['shopping_list_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_list_items');
    }
};
