<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Product;
use App\Models\ShoppingList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Marca artículos en la despensa. `have: true` = lo hay en casa (con `remaining` opcional: cuánto queda, "300 g", "1.5 kg"; sin él = suficiente, como sal, aceite o especias). `have: false` = se acabó (cuando una receta lo pida irá a la lista, y al comprarlo vuelve a quedar en casa). `forget: true` = no llevarlo en la despensa. Con `add_to_list: true` lo que se marca como acabado se agrega también a una lista (`list_id` pendiente, o sin él una lista nueva "Despensa …"; no se duplica si ya está pendiente ahí).')]
class UpdatePantry extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.have' => ['required_without:items.*.forget', 'boolean'],
            'items.*.remaining' => ['nullable', 'string', 'max:30'],
            'items.*.forget' => ['nullable', 'boolean'],
            'add_to_list' => ['nullable', 'boolean'],
            'list_id' => ['nullable', 'integer', 'exists:shopping_lists,id'],
        ], [], ['items' => 'artículos', 'items.*.product_id' => 'artículo', 'items.*.remaining' => 'cantidad', 'list_id' => 'lista']);
        $list = null;

        $products = Product::findMany(array_column($data['items'], 'product_id'))->keyBy('id');
        $updated = [];
        foreach ($data['items'] as $i => $item) {
            $product = $products->get($item['product_id']);
            $qty = $product->parseAmount($item['remaining'] ?? null);
            if ($qty === false) {
                throw ValidationException::withMessages(["items.$i.remaining" => "No entendí la cantidad de «{$product->name}»: escríbela como «300 g» o «1.5 kg» (se mide en {$product->unit->label()})."]);
            }

            if (! empty($item['forget'])) {
                $product->forceFill(['pantry' => null, 'pantry_qty' => null])->save();
            } else {
                $product->setPantry((bool) $item['have'], $qty);
            }
            $row = ['product_id' => $product->id, 'name' => $product->fullName(), ...Present::pantry($product)];
            if ((! empty($data['add_to_list']) || isset($data['list_id'])) && $product->pantry === Product::PANTRY_OUT) {
                $list ??= isset($data['list_id']) ? ShoppingList::find($data['list_id']) : ShoppingList::forPantry();
                $row['added_to_list'] = $list->addProductOnce($product);
            }
            $updated[] = $row;
        }

        return $this->json(['updated' => $updated] + ($list ? ['list' => ['id' => $list->id, 'name' => $list->name, 'url' => route('lists.show', $list)]] : []));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object([
                'product_id' => $schema->integer()->description('Id del artículo (de search-products).')->required(),
                'have' => $schema->boolean()->description('true = está en casa; false = se acabó.'),
                'remaining' => $schema->string()->description('Cuánto queda, con unidad ("300 g", "2 pza"); sin él = suficiente.'),
                'forget' => $schema->boolean()->description('true = dejar de llevarlo en la despensa.'),
            ]))->description('Artículos a marcar.')->required(),
            'add_to_list' => $schema->boolean()->description('true = lo que se acabó se agrega también a una lista de compras.'),
            'list_id' => $schema->integer()->description('Lista pendiente donde agregarlo (de list-shopping-lists); sin ella se crea una.'),
        ];
    }
}
