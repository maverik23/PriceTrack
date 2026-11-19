<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category')->nullable()->index();
            // Porciones que rinde la receta tal como está escrita
            $table->unsignedSmallInteger('servings')->default(4);
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->text('instructions')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            // Artículo del súper (da el costo y permite mandarlo a una lista). Null = texto libre.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            // Con artículo guarda su nombre, para no perderlo si el artículo se elimina
            $table->string('name');
            $table->decimal('quantity', 10, 3)->nullable();
            $table->string('unit', 10)->nullable();
            $table->string('note')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('meal_plan_entries', function (Blueprint $table) {
            $table->id();
            $table->date('planned_on')->index();
            $table->string('meal', 20);
            $table->foreignId('recipe_id')->nullable()->constrained()->cascadeOnDelete();
            // Sin receta: "Sobras", "Comer fuera"…
            $table->string('title')->nullable();
            $table->unsignedSmallInteger('servings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plan_entries');
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
    }
};
