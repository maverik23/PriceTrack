<?php

namespace App\Mcp\Tools;

use App\Enums\Unit;
use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Product;
use App\Models\ShoppingList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Agrega un artículo a una lista de compras. La cantidad se da en presentaciones habituales (`quantity`: 2 = dos de 1 L) o como contenido (`amount` + `amount_unit`: 610 g). Si el artículo ya está en la lista, la cantidad se suma. El artículo debe existir (search-products / create-product).')]
class AddListItem extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'list_id' => ['required', 'integer', 'exists:shopping_lists,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['nullable', 'numeric', 'gt:0', 'max:9999', 'prohibits:amount'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999', 'required_with:amount_unit'],
            'amount_unit' => ['nullable', Rule::enum(Unit::class), 'required_with:amount'],
        ], [], ['list_id' => 'lista', 'product_id' => 'artículo', 'quantity' => 'cantidad', 'amount' => 'contenido', 'amount_unit' => 'unidad']);

        $list = ShoppingList::findOrFail($data['list_id']);
        $product = Product::findOrFail($data['product_id']);

        $quantity = (float) ($data['quantity'] ?? 1);
        if (isset($data['amount'])) {
            $unit = Unit::from($data['amount_unit']);
            if (! $unit->isCompatibleWith($product->unit)) {
                throw ValidationException::withMessages([
                    'amount_unit' => "«{$product->name}» se mide en {$product->unit->label()}; usa una unidad compatible.",
                ]);
            }
            $quantity = $data['amount'] * $unit->toBase() / $product->standardBaseQty();
        }

        $item = $list->items()->firstOrNew(['product_id' => $product->id]);
        $item->quantity = min(9999, max(0.0001, round(($item->exists ? $item->quantity : 0) + $quantity, 4)));
        $item->save();

        return $this->json(['item' => Present::listItem($item->load(['product.prices.provider', 'price.provider']))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()->description('Id de la lista.')->required(),
            'product_id' => $schema->integer()->description('Id del artículo.')->required(),
            'quantity' => $schema->number()->description('Cuántas presentaciones habituales se necesitan (1 por defecto).'),
            'amount' => $schema->number()->description('Alternativa a quantity: contenido que se necesita (610).'),
            'amount_unit' => $schema->string()->enum(array_column(Unit::cases(), 'value'))->description('Unidad de amount (g, kg, ml, l, item).'),
        ];
    }
}
