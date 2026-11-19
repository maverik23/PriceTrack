<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Provider;
use App\Models\ShoppingList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Cambia una lista de compras (`list_id`): nombre, fecha, notas, marcarla comprada (`done: true`; false la reabre) y el total del ticket de una tienda (`ticket_provider_id` + `ticket_total`: lo que de verdad se pagó ahí, con descuentos; es lo que va a los gastos; `ticket_total: null` lo quita y vuelve la suma de lo marcado).')]
class UpdateShoppingList extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'list_id' => ['required', 'integer'],
            'name' => ['sometimes', 'string', 'max:120'],
            'shopped_on' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
            'done' => ['sometimes', 'boolean'],
            'ticket_provider_id' => ['nullable', 'integer', 'exists:providers,id', 'required_with:ticket_total'],
            'ticket_total' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ], [], ['name' => 'nombre', 'shopped_on' => 'fecha', 'ticket_provider_id' => 'tienda del ticket', 'ticket_total' => 'total del ticket']);

        $list = $this->find(ShoppingList::class, $data['list_id'], 'una lista');
        $list->update(collect($data)->only(['name', 'shopped_on', 'notes'])->all());
        if (array_key_exists('done', $data)) {
            $list->markDone($data['done']);
        }
        if (! empty($data['ticket_provider_id'])) {
            $request->has('ticket_total') && $data['ticket_total'] !== null
                ? $list->tickets()->updateOrCreate(['provider_id' => $data['ticket_provider_id']], ['total' => round((float) $data['ticket_total'], 2)])
                : $list->tickets()->where('provider_id', $data['ticket_provider_id'])->delete();
        }
        $list = $list->fresh(['items.price.provider', 'items.product.prices', 'tickets']);

        return $this->json([
            'list' => Present::shoppingList($list),
            'paid_by_store' => collect($list->paidByProvider())->map(fn ($p, $id) => ['store' => Provider::find($id)?->name] + $p)->values()->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()->required(),
            'name' => $schema->string(),
            'shopped_on' => $schema->string()->description('AAAA-MM-DD.'),
            'notes' => $schema->string(),
            'done' => $schema->boolean()->description('true = comprada; false = reabrir.'),
            'ticket_provider_id' => $schema->integer()->description('Tienda del ticket.'),
            'ticket_total' => $schema->number()->description('Total pagado en esa tienda; null lo quita.'),
        ];
    }
}
