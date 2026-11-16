<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada tienda vende su propia versión del artículo ("Agua": Bonafont en una,
 * Kirkland en otra): marca, código de barras, código de la tienda y nombre por
 * artículo + tienda. El enlace pasa a ser opcional (Walmart, Sam's: a mano).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_links', function (Blueprint $table) {
            $table->string('url', 1000)->nullable()->change();
            $table->string('store_name', 200)->nullable()->after('url');
            $table->string('brand', 100)->nullable()->after('store_name');
            $table->string('barcode', 14)->nullable()->index()->after('brand');
            $table->string('sku', 40)->nullable()->after('barcode');
        });
    }

    public function down(): void
    {
        DB::table('product_links')->whereNull('url')->delete();

        Schema::table('product_links', function (Blueprint $table) {
            $table->dropIndex(['barcode']);
            $table->dropColumn(['store_name', 'brand', 'barcode', 'sku']);
            $table->string('url', 1000)->nullable(false)->change();
        });
    }
};
