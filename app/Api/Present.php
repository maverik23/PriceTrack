<?php

namespace App\Api;

use App\Mcp\Present as Base;
use App\Models\Attachment;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductLink;
use App\Models\Provider;
use App\Models\ShoppingList;

/**
 * Cómo se le entregan los datos a la app móvil (arreglos listos para JSON). Las direcciones
 * son relativas a /api/v1, para que la app las pida con su servidor y su token.
 */
class Present
{
    /** @return array<string, mixed> */
    public static function provider(Provider $provider): array
    {
        return [
            'id' => $provider->id,
            'name' => $provider->name,
            'notes' => $provider->notes,
            'groceries' => (bool) $provider->groceries,
            'chain' => $provider->chain,
            'chain_name' => $provider->chainName(),
            'branch' => $provider->branch,
            'branch_name' => $provider->branch_name,
            'branch_label' => $provider->branchLabel(),
            'prices_count' => $provider->prices_count ?? null,
            'last_price_on' => isset($provider->prices_max_observed_on) ? substr((string) $provider->prices_max_observed_on, 0, 10) : null,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function attachment(?Attachment $attachment): ?array
    {
        return $attachment ? [
            'id' => $attachment->id,
            'kind' => $attachment->kind,
            'title' => $attachment->title,
            'name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'url' => "attachments/{$attachment->id}",
            'thumb' => "attachments/{$attachment->id}".($attachment->thumb_path ? '?thumb=1' : ''),
        ] : null;
    }

    /** @return array<string, mixed> */
    public static function price(Price $price): array
    {
        return Base::price($price) + [
            'id' => $price->id,
            'product_id' => $price->product_id,
            'pack_qty' => $price->pack_qty,
            'size' => $price->size,
            'unit' => $price->unit->value,
            'notes' => $price->notes,
            'list_id' => $price->shopping_list_id,
        ];
    }

    /**
     * Artículo para las listas: datos, foto de portada y el último precio de cada tienda
     * (del más barato por unidad al más caro). Necesita `prices.provider` y `attachments`.
     *
     * @return array<string, mixed>
     */
    public static function product(Product $product): array
    {
        return Base::product($product) + [
            'unit_label' => $product->unit->label(),
            'notes' => $product->notes,
            'photo' => self::attachment($product->coverPhoto()),
        ];
    }

    /**
     * Ficha del artículo: además, nutrición y conversiones, todo su historial de precios,
     * su versión en cada tienda y sus fotos. Necesita `prices.provider`, `links.provider` y `attachments`.
     *
     * @return array<string, mixed>
     */
    public static function productDetail(Product $product): array
    {
        $latest = $product->latestPrices();
        $cheapest = $latest->first();
        $dearest = $latest->last();

        return self::product($product) + [
            'piece_weight' => $product->piece_weight,
            'cup_grams' => $product->cup_grams,
            'kcal' => $product->kcal,
            'protein' => $product->protein,
            'carbs' => $product->carbs,
            'fat' => $product->fat,
            'compare_label' => $product->unit->compareLabel(),
            // Cuánto más barato es el más barato contra el más caro (0.25 = 25 %)
            'savings' => ($cheapest && $dearest && $dearest->unit_price > 0) ? round(1 - $cheapest->unit_price / $dearest->unit_price, 4) : null,
            'prices' => $product->prices
                ->sortByDesc(fn (Price $p) => $p->observed_on->format('Ymd').str_pad((string) $p->id, 10, '0', STR_PAD_LEFT))
                ->map(fn (Price $p) => self::price($p))->values()->all(),
            'links' => $product->links->map(fn (ProductLink $l) => self::link($l))->values()->all(),
            'attachments' => $product->attachments->map(fn (Attachment $a) => self::attachment($a))->values()->all(),
        ];
    }

    /** El artículo en una tienda. @return array<string, mixed> */
    public static function link(ProductLink $link): array
    {
        return [
            'id' => $link->id,
            'provider_id' => $link->provider_id,
            'provider' => $link->provider?->name,
            'url' => $link->url,
            'brand' => $link->brand,
            'barcode' => $link->barcode,
            'sku' => $link->sku,
            'store_name' => $link->store_name,
            'checked_at' => $link->checked_at?->toIso8601String(),
            'error' => $link->error,
        ];
    }

    /**
     * Resumen de una lista. Necesita `items.price.provider`, `items.product.prices` y `tickets`.
     *
     * @return array<string, mixed>
     */
    public static function shoppingList(ShoppingList $list): array
    {
        $providers = $list->items->map(fn ($item) => $item->price?->provider?->name)->filter()->unique()->values();

        return collect(Base::shoppingList($list))->except('url')->all() + [
            'completed_at' => $list->completed_at?->toIso8601String(),
            'bought_at' => $providers->all(),
        ];
    }
}
