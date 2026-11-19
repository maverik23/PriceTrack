<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\ShoppingListItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Quita un artículo de una lista de compras. Los precios capturados se conservan en el historial.')]
#[IsDestructive]
class RemoveListItem extends Tool
{
    public function handle(Request $request): Response
    {
        $item = $this->find(ShoppingListItem::class, $request->get('item_id'), 'un artículo de lista', ['product']);
        $item->delete();

        return $this->json(['removed' => $item->product->name, 'list_id' => $item->shopping_list_id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['item_id' => $schema->integer()->description('`item_id` del renglón (de get-shopping-list).')->required()];
    }
}
