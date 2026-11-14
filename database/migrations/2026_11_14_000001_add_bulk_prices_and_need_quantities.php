<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - prices.bulk: precio a granel (se lleva la cantidad que quieras: manzana por kg).
 * - shopping_list_items.quantity pasa a significar siempre "lo que se necesita, en
 *   presentaciones habituales del artículo" (antes, en lo comprado, eran paquetes
 *   del precio elegido). Lo pagado ahora sale de paquetes completos o de lo pesado.
 *
 * Las compras existentes se convierten para que su total no cambie: la cantidad se
 * pasa a presentaciones habituales y, si era fraccionaria (0.61 de un paquete), el
 * precio queda a granel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->boolean('bulk')->default(false)->after('unit');
        });

        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 4)->default(1)->change();
        });

        $this->convertQuantities();
    }

    public function down(): void
    {
        $this->convertQuantities(back: true);

        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->decimal('quantity', 8, 2)->default(1)->change();
        });

        Schema::table('prices', function (Blueprint $table) {
            $table->dropColumn('bulk');
        });
    }

    /** Cantidades de lo comprado: paquetes del precio elegido ⇄ presentaciones habituales. */
    public function convertQuantities(bool $back = false): void
    {
        DB::table('shopping_list_items as i')
            ->join('prices as p', 'p.id', '=', 'i.price_id')
            ->join('products as pr', 'pr.id', '=', 'i.product_id')
            ->select('i.id', 'i.quantity', 'p.id as price_id', 'p.base_qty', 'pr.size', 'pr.unit')
            ->chunkById(500, function ($rows) use ($back) {
                foreach ($rows as $row) {
                    $standard = (float) $row->size * (in_array($row->unit, ['l', 'kg'], true) ? 1000 : 1);
                    $pack = (float) $row->base_qty;
                    if ($standard <= 0 || $pack <= 0) {
                        continue;
                    }

                    $quantity = (float) $row->quantity;
                    if (! $back && abs($quantity - round($quantity)) > 0.000001) {
                        DB::table('prices')->where('id', $row->price_id)->update(['bulk' => true]);
                    }

                    $converted = $back ? $quantity * $standard / $pack : $quantity * $pack / $standard;
                    DB::table('shopping_list_items')->where('id', $row->id)->update(['quantity' => round($converted, 4)]);
                }
            }, 'i.id', 'id');
    }
};
