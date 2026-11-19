<?php

use App\Services\StoreBranches;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La sucursal pasa a ser de cada proveedor (antes había un solo ajuste "tu tienda Chedraui").
 * Se adivina la cadena de cada proveedor por su nombre o por las direcciones de sus artículos,
 * y la tienda Chedraui que ya estaba elegida se pasa a los proveedores de Chedraui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->string('chain', 20)->nullable()->after('notes');
            // "auto" (la más cercana / la que asigne la tienda) o el id de la sucursal
            $table->string('branch', 60)->nullable()->after('chain');
            $table->string('branch_name', 150)->nullable()->after('branch');
        });

        $chedraui = DB::table('settings')->where('key', 'chedraui_store')->value('value');

        foreach (DB::table('providers')->get() as $provider) {
            $chain = StoreBranches::guess($provider->name);
            if (! $chain) {
                $urls = DB::table('product_links')->where('provider_id', $provider->id)->whereNotNull('url')->pluck('url');
                $chain = $urls->map(fn ($url) => StoreBranches::chainFor($url))->filter()->first();
            }
            if ($chain) {
                DB::table('providers')->where('id', $provider->id)->update([
                    'chain' => $chain,
                    'branch' => $chain === 'chedraui' ? $chedraui : null,
                ]);
            }
        }

        DB::table('settings')->where('key', 'chedraui_store')->delete();
    }

    public function down(): void
    {
        $branch = DB::table('providers')->where('chain', 'chedraui')->whereNotNull('branch')->value('branch');
        if ($branch) {
            DB::table('settings')->updateOrInsert(['key' => 'chedraui_store'], ['value' => $branch, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn(['chain', 'branch', 'branch_name']);
        });
    }
};
