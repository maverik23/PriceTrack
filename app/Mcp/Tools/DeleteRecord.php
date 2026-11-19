<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Price;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\ShoppingList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Borra del súper o la cocina: un precio mal capturado (`price`, el `price_id` de get-product), una lista de compras (`shopping_list`; los precios capturados se conservan), un artículo (`product`, con todo su historial de precios) o una receta (`recipe`; donde era sub-receta queda como texto libre). Confirma con la persona antes.')]
#[IsDestructive]
class DeleteRecord extends Tool
{
    private const TYPES = [
        'price' => [Price::class, 'un precio'],
        'shopping_list' => [ShoppingList::class, 'una lista'],
        'product' => [Product::class, 'un artículo'],
        'recipe' => [Recipe::class, 'una receta'],
    ];

    public function handle(Request $request): Response
    {
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(self::TYPES))], 'id' => ['required', 'integer']], [], ['type' => 'tipo']);
        [$class, $what] = self::TYPES[$data['type']];
        $record = $this->find($class, $data['id'], $what);
        $label = $record->name ?? ($record instanceof Price ? $record->product?->name.' en '.$record->provider?->name : null);
        $usedIn = $record instanceof Recipe ? $record->usedAsIngredient()->with('recipe')->get()->pluck('recipe.name')->filter()->unique()->values()->all() : [];

        $record->delete();

        return $this->json(['deleted' => $data['type'], 'id' => $data['id'], 'name' => $label] + ($usedIn ? ['now_free_text_in' => $usedIn] : []));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(array_keys(self::TYPES))->description('price, shopping_list, product o recipe.')->required(),
            'id' => $schema->integer()->required(),
        ];
    }
}
