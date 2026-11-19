<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Actualizar todos los precios" corre en el servidor: aquí se guarda su avance. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_runs', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            // running | done | cancelled
            $table->string('status', 12)->default('running')->index();
            // Los enlaces a consultar, en orden, y cuántos van
            $table->json('link_ids');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('ok')->default(0);
            $table->json('failures')->nullable();
            $table->string('current', 250)->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_runs');
    }
};
