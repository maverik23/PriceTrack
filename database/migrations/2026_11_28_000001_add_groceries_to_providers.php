<?php

use App\Models\Provider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Si lo que se compra en la tienda es súper (Home Depot o una ferretería no cuentan en el gasto del súper). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->boolean('groceries')->default(true)->after('notes');
        });

        foreach (DB::table('providers')->get(['id', 'name']) as $provider) {
            if (! Provider::guessGroceries($provider->name)) {
                DB::table('providers')->where('id', $provider->id)->update(['groceries' => false]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('providers', fn (Blueprint $table) => $table->dropColumn('groceries'));
    }
};
