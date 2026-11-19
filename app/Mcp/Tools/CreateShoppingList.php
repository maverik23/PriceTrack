<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\ShoppingList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Crea una lista de compras. Con `copy_from` copia los artículos (sin precios) de una lista anterior, útil para la despensa de cada semana.')]
class CreateShoppingList extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'shopped_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
            'copy_from' => ['nullable', 'integer', 'exists:shopping_lists,id'],
        ], [], ['name' => 'nombre', 'shopped_on' => 'fecha', 'copy_from' => 'lista a copiar']);

        $date = $this->date($data['shopped_on'] ?? null, today()->toImmutable());
        $list = ShoppingList::create([
            'name' => filled($data['name'] ?? null) ? $data['name'] : 'Despensa '.$date->translatedFormat('d M Y'),
            'shopped_on' => $date,
            'notes' => $data['notes'] ?? null,
        ]);

        if (isset($data['copy_from'])) {
            ShoppingList::findOrFail($data['copy_from'])->items->each(
                fn ($item) => $list->items()->create(['product_id' => $item->product_id, 'quantity' => $item->quantity])
            );
        }

        return $this->json(['created' => Present::shoppingList($list->load(['items.price', 'items.product.prices']))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Nombre; por defecto "Despensa <fecha>".'),
            'shopped_on' => $schema->string()->description('Fecha de la compra, AAAA-MM-DD (hoy por defecto).'),
            'notes' => $schema->string(),
            'copy_from' => $schema->integer()->description('Id de una lista anterior de la que copiar los artículos.'),
        ];
    }
}
