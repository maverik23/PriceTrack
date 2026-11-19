<?php

namespace App\Mcp\Tools;

use App\Enums\Unit;
use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Price;
use App\Models\Product;
use App\Models\ShoppingList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Registra el precio de un artículo en una tienda. `price` es lo que cuesta el paquete completo (pack_qty × size unit: 6 × 1 l); con `bulk` es precio a granel por ese contenido ("por kg" = size 1, unit kg). Sin size/unit se usa la presentación habitual del artículo. Con `list_id` queda con la fecha de esa lista (y corrige el de ese día si ya existe); sin lista, con la fecha de hoy. El historial anterior se conserva.')]
class RecordPrice extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'provider_id' => ['required', 'integer', 'exists:providers,id'],
            'price' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'pack_qty' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'size' => ['nullable', 'numeric', 'gt:0', 'max:9999999', 'required_with:unit'],
            'unit' => ['nullable', Rule::enum(Unit::class), 'required_with:size'],
            'bulk' => ['nullable', 'boolean'],
            'list_id' => ['nullable', 'integer', 'exists:shopping_lists,id'],
        ], [], ['product_id' => 'artículo', 'provider_id' => 'tienda', 'price' => 'precio', 'list_id' => 'lista']);

        $product = Product::findOrFail($data['product_id']);
        $unit = filled($data['unit'] ?? null) ? Unit::from($data['unit']) : $product->unit;
        if (! $unit->isCompatibleWith($product->unit)) {
            throw ValidationException::withMessages([
                'unit' => "«{$product->name}» se mide en {$product->unit->label()}; usa una unidad compatible.",
            ]);
        }

        $list = isset($data['list_id']) ? ShoppingList::findOrFail($data['list_id']) : null;
        $price = Price::record($product, (int) $data['provider_id'], $list, [
            'price' => $data['price'],
            'pack_qty' => $data['pack_qty'] ?? 1,
            'size' => $data['size'] ?? $product->size,
            'unit' => $unit,
            'bulk' => (bool) ($data['bulk'] ?? false),
        ]);
        // Si en esa lista ya estaba comprado en esta tienda, la compra pasa al precio nuevo
        $list?->items()->where('product_id', $product->id)->with('price')->first()?->followPrice($price);

        $product->load('prices.provider');

        return $this->json([
            'recorded' => Present::price($price->load('provider')),
            'is_cheapest_now' => $product->cheapestPrice()?->id === $price->id,
            'latest_prices' => Present::product($product)['latest_prices'],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->description('Id del artículo.')->required(),
            'provider_id' => $schema->integer()->description('Id de la tienda (de list-providers).')->required(),
            'price' => $schema->number()->description('Precio en pesos del paquete completo (o del contenido, si es a granel).')->required(),
            'pack_qty' => $schema->integer()->description('Piezas del paquete (6 en "6 × 1 L"); 1 por defecto.'),
            'size' => $schema->number()->description('Contenido de cada pieza; por defecto el de la presentación habitual.'),
            'unit' => $schema->string()->enum(array_column(Unit::cases(), 'value'))->description('Unidad de size; de la misma dimensión que el artículo.'),
            'bulk' => $schema->boolean()->description('true si se vende a granel (precio por kg, por 100 g…).'),
            'list_id' => $schema->integer()->description('Lista de compras desde la que se captura (opcional).'),
        ];
    }
}
