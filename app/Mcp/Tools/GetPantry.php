<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\PantryController;
use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Product;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('La despensa: artículos que hay en casa (`stocked`, con lo que queda en `remaining`; null = suficiente) y los que se acabaron (`out`). Lo que está en casa no se manda a la lista al usar add-ingredients-to-list, y si tiene cantidad solo se manda lo que falta. `value` = lo que vale al precio más barato (`estimated`: si dice suficiente se cuenta una presentación) y `total_value` la suma; `in_list` = la lista pendiente donde ya está sin comprar.')]
#[IsReadOnly]
class GetPantry extends Tool
{
    public function handle(Request $request): Response
    {
        $products = Product::with('prices')->whereNotNull('pantry')->orderBy('name')->get();
        $pending = PantryController::pendingItems();
        $row = fn (Product $p) => ['product_id' => $p->id, 'name' => $p->fullName(), 'category' => $p->category, ...Present::pantry($p),
            'value' => $p->pantryValue()['value'] ?? null, 'estimated' => $p->pantryValue()['estimated'] ?? null, 'in_list' => $pending[$p->id] ?? null];
        $inPantry = $products->filter->inPantry();

        return $this->json([
            'total_value' => round($inPantry->sum(fn (Product $p) => $p->pantryValue()['value'] ?? 0), 2),
            'in_pantry' => $inPantry->map($row)->values()->all(),
            'ran_out' => $products->where('pantry', Product::PANTRY_OUT)->map($row)->values()->all(),
            'url' => route('pantry.index'),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
