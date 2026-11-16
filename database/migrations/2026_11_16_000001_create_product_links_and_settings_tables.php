<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Página del artículo en cada tienda, para consultar su precio en línea
        Schema::create('product_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('url', 1000);
            $table->timestamp('checked_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'provider_id']);
        });

        // Ajustes de la app (por ahora, el código postal para consultar precios)
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('product_links');
    }
};
