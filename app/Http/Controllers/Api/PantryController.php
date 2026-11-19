<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\PantryController as WebPantry;
use App\Models\Product;

/** La despensa completa para la app móvil (marcar y mandar a la lista usan las rutas de la web). */
class PantryController extends Controller
{
    public function index()
    {
        $products = Product::with('prices')->orderBy('name')->get();
        $pending = WebPantry::pendingItems();
        $rows = $products->map(fn (Product $p) => collect(WebPantry::row($p, $pending))->except(['url', 'action', 'to_list'])->all())->values();

        return response()->json([
            'data' => $rows,
            'lists' => WebPantry::lists(),
            'categories' => Product::distinctValues('category'),
            'counts' => [
                'stocked' => $products->where('pantry', Product::PANTRY_STOCKED)->count(),
                'out' => $products->where('pantry', Product::PANTRY_OUT)->count(),
            ],
            // Lo que vale lo que hay en casa
            'value' => round($rows->sum(fn (array $r) => $r['value'] ?? 0), 2),
        ]);
    }
}
