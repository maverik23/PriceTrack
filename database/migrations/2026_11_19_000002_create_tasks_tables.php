<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            // Sin lista = "General"
            $table->foreignId('task_list_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->text('notes')->nullable();
            $table->date('due_on')->nullable()->index();
            $table->dateTime('completed_at')->nullable()->index();
            // 1 baja, 2 normal, 3 alta
            $table->unsignedTinyInteger('priority')->default(2);
            // Repetición: cada N días / semanas / meses / años, contando desde que se completa
            $table->unsignedSmallInteger('repeat_every')->nullable();
            $table->string('repeat_unit', 10)->nullable();
            // La tarea repetitiva que, al completarse, creó esta
            $table->foreignId('repeated_from_id')->nullable()->constrained('tasks')->nullOnDelete();
            // A qué se refiere (un bien, en Hogar), opcional
            $table->nullableMorphs('taskable');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('task_lists');
    }
};
