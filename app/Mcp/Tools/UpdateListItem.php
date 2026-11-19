<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Price;
use App\Models\Provider;
use App\Models\ShoppingListItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Cambia un artículo de una lista: la cantidad, marcarlo como comprado en una tienda (`purchased_at_provider_id`: usa el último precio de esa tienda, que cuenta como gasto en la fecha de la lista), cuánto se llevó (`bought_qty`: paquetes de ese precio, o a granel múltiplos de su contenido; null = los que cubren lo pedido) o desmarcar la compra (`purchased`: false).')]
class UpdateListItem extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'numeric', 'gt:0', 'max:9999'],
            'purchased_at_provider_id' => ['nullable', 'integer', 'exists:providers,id'],
            'purchased' => ['nullable', 'boolean'],
            'bought_qty' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
        ], [], ['quantity' => 'cantidad', 'purchased_at_provider_id' => 'tienda', 'bought_qty' => 'cantidad comprada']);

        $item = $this->find(ShoppingListItem::class, $data['item_id'], 'un artículo de lista', ['product.prices.provider']);

        if (isset($data['quantity'])) {
            $item->quantity = $data['quantity'];
        }
        if (isset($data['purchased_at_provider_id'])) {
            $price = $item->product->latestPrices()->first(fn (Price $p) => $p->provider_id === (int) $data['purchased_at_provider_id']);
            if (! $price) {
                $store = Provider::find($data['purchased_at_provider_id'])->name;
                throw ValidationException::withMessages([
                    'purchased_at_provider_id' => "«{$item->product->name}» no tiene precio en {$store}; regístralo antes con record-price.",
                ]);
            }
            $item->price_id = $price->id;
        } elseif (($data['purchased'] ?? null) === false) {
            $item->price_id = null;
        }
        $item->save();
        // Después de elegir la tienda (cambiarla reinicia lo comprado)
        if ($request->has('bought_qty')) {
            if ($data['bought_qty'] !== null && ! $item->price_id) {
                throw ValidationException::withMessages(['bought_qty' => 'Marca antes dónde se compró (purchased_at_provider_id).']);
            }
            $item->update(['bought_qty' => $data['bought_qty']]);
        }

        return $this->json(['item' => Present::listItem($item->load('price.provider'))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->integer()->description('`item_id` del renglón (de get-shopping-list).')->required(),
            'quantity' => $schema->number()->description('Nueva cantidad, en presentaciones habituales.'),
            'purchased_at_provider_id' => $schema->integer()->description('Id de la tienda donde se compró.'),
            'purchased' => $schema->boolean()->description('false para desmarcar la compra.'),
            'bought_qty' => $schema->number()->description('Cuánto se llevó: paquetes del precio elegido (3), o a granel múltiplos de su contenido (1.2 de "por kg"). null = los que cubren lo pedido.'),
        ];
    }
}
