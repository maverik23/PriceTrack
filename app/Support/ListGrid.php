<?php

namespace App\Support;

use App\Enums\Unit;
use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;

/**
 * Serializa una lista de compra para la tabla tipo Excel (Alpine.js).
 */
class ListGrid
{
    public static function page(ShoppingList $list): array
    {
        return [
            'listId' => $list->id,
            'priceDate' => $list->priceDate()->format('Y-m-d'),
            'priceDateLabel' => $list->priceDate()->translatedFormat('d M Y'),
            'urls' => [
                'items' => route('lists.items.store', $list, false),
            ],
            'units' => Unit::meta(),
            'providers' => Provider::orderBy('name')->get(['id', 'name'])->toArray(),
            'products' => Product::orderBy('name')->get()->map(fn (Product $p) => self::product($p))->values(),
            'rows' => $list->items->map(fn (ShoppingListItem $item) => self::row($item, $list))->values(),
        ];
    }

    public static function product(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'brand' => $product->brand,
            'category' => $product->category,
            'size' => $product->size,
            'unit' => $product->unit->value,
            'label' => num($product->size).' '.$product->unit->label(),
            'url' => route('products.show', $product, false),
        ];
    }

    public static function row(ShoppingListItem $item, ShoppingList $list): array
    {
        $cells = [];
        foreach ($item->product->latestPrices() as $price) {
            $cells[$price->provider_id] = self::price($price, $list);
        }

        return [
            'id' => $item->id,
            'quantity' => $item->quantity,
            'price_id' => $item->price_id,
            'selected' => self::price($item->price, $list),
            'product' => self::product($item->product),
            'cells' => (object) $cells,
        ];
    }

    public static function item(ShoppingListItem $item, ShoppingList $list): array
    {
        $item->load('price');

        return [
            'id' => $item->id,
            'quantity' => $item->quantity,
            'price_id' => $item->price_id,
            'selected' => self::price($item->price, $list),
        ];
    }

    public static function price(?Price $price, ShoppingList $list): ?array
    {
        if (! $price) {
            return null;
        }

        $content = num($price->size).' '.$price->unit->label();

        return [
            'id' => $price->id,
            'provider_id' => $price->provider_id,
            'list_id' => $price->shopping_list_id,
            'price' => $price->price,
            'pack_qty' => $price->pack_qty,
            'size' => $price->size,
            'unit' => $price->unit->value,
            'presentation' => $price->pack_qty > 1 ? $price->pack_qty.' × '.$content : $content,
            'base_qty' => $price->base_qty,
            'unit_price' => $price->unit_price,
            'observed_on' => $price->observed_on->format('Y-m-d'),
            'date_label' => $price->observed_on->translatedFormat('d M y'),
            // Precio ya registrado para la fecha de esta lista
            'current' => $price->observed_on->isSameDay($list->priceDate()),
        ];
    }
}
