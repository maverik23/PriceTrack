<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            // Bien, receta… (nombre de la clase, como `taskable`)
            $table->morphs('attachable');
            // photo | document
            $table->string('kind', 10);
            $table->string('title', 150)->nullable();
            // En el disco local (storage/app/private), fuera de lo público
            $table->string('path');
            // Miniatura hecha en el navegador (fotos); null = usar la original
            $table->string('thumb_path')->nullable();
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
