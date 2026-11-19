<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            // Orden en la galería; la primera foto es la portada. Con 0 en todas se respeta el orden de subida (id).
            $table->unsignedSmallInteger('position')->default(0)->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
