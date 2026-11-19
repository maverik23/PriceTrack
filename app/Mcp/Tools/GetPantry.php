<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Product;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('La despensa: artículos que hay en casa (`stocked`, con lo que queda en `remaining`; null = suficiente) y los que se acabaron (`out`). Lo que está en casa no se manda a la lista al usar add-ingredients-to-list, y si tiene cantidad solo se manda lo que falta.')]
#[IsReadOnly]
class GetPantry extends Tool
{
    public function handle(Request $request): Response
    {
        $products = Product::whereNotNull('pantry')->orderBy('name')->get();
        $row = fn (Product $p) => ['product_id' => $p->id, 'name' => $p->fullName(), 'category' => $p->category, ...Present::pantry($p)];

        return $this->json([
            'in_pantry' => $products->filter->inPantry()->map($row)->values()->all(),
            'ran_out' => $products->where('pantry', Product::PANTRY_OUT)->map($row)->values()->all(),
            'url' => route('pantry.index'),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
