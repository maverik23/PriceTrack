<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Price;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Una lista de compras con sus artículos. `quantity` es lo que se necesita, en presentaciones habituales del artículo (0.61 de 1 kg = 610 g). Por artículo: si ya se compró y dónde, lo pagado, y lo que costaría esa cantidad en cada tienda (`cost_for_need`: paquetes completos o lo justo si es a granel), de la más barata a la más cara. Incluye el total por tienda si todo se comprara ahí.')]
#[IsReadOnly]
class GetShoppingList extends Tool
{
    public function handle(Request $request): Response
    {
        $list = $this->find(ShoppingList::class, $request->get('list_id'), 'una lista', [
            'items' => fn ($q) => $q->orderBy('id'), 'items.product.prices.provider', 'items.price.provider',
        ]);

        // Lo que costaría toda la lista en cada tienda (solo con los artículos que tiene)
        $byStore = [];
        foreach ($list->items as $item) {
            /** @var ShoppingListItem $item */
            foreach ($item->product->latestPrices() as $price) {
                /** @var Price $price */
                $name = $price->provider->name;
                $byStore[$name] ??= ['provider_id' => $price->provider_id, 'provider' => $name, 'total' => 0.0, 'items_with_price' => 0];
                $byStore[$name]['total'] = round($byStore[$name]['total'] + $price->costFor($item->needBaseQty()), 2);
                $byStore[$name]['items_with_price']++;
            }
        }

        return $this->json([
            ...Present::shoppingList($list),
            'items' => $list->items->map(fn (ShoppingListItem $i) => Present::listItem($i))->all(),
            'total_by_store' => array_values($byStore),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['list_id' => $schema->integer()->description('Id de la lista (de list-shopping-lists).')->required()];
    }
}
