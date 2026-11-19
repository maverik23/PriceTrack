<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\ShoppingList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Listas de compras, de la más reciente a la más antigua, con cuántos artículos tienen, cuántos ya se compraron, lo pagado y el total estimado.')]
#[IsReadOnly]
class ListShoppingLists extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:50']]);

        $lists = ShoppingList::with(['items.price', 'items.product.prices', 'tickets'])
            ->orderByDesc('shopped_on')->orderByDesc('id')
            ->take($data['limit'] ?? 10)->get();

        return $this->json([
            'total' => ShoppingList::count(),
            'lists' => $lists->map(fn (ShoppingList $l) => Present::shoppingList($l))->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()->description('Cuántas listas (10 por defecto, hasta 50).')];
    }
}
