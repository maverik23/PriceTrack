<?php

namespace App\Mcp;

use App\Models\Price;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Task;

/** Cómo se le describen los datos de la app a un asistente (arreglos listos para JSON). */
class Present
{
    /** @return array<string, mixed> */
    public static function price(Price $price): array
    {
        return [
            'price_id' => $price->id,
            'provider_id' => $price->provider_id,
            'provider' => $price->provider?->name,
            'price' => $price->price,
            'presentation' => $price->presentationLabel(),
            'bulk' => $price->bulk,
            'unit_price' => round($price->comparePrice(), 2),
            'unit_price_per' => $price->unit->compareLabel(),
            'observed_on' => $price->observed_on->toDateString(),
        ];
    }

    /**
     * Artículo con el último precio de cada tienda, del más barato por unidad al más caro.
     * Necesita `prices.provider` cargado.
     *
     * @return array<string, mixed>
     */
    public static function product(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'brand' => $product->brand,
            'category' => $product->category,
            'presentation' => $product->sizeLabel(),
            'size' => $product->size,
            'unit' => $product->unit->value,
            'barcode' => $product->barcode,
            'latest_prices' => $product->latestPrices()->map(fn (Price $p) => self::price($p))->all(),
        ];
    }

    /**
     * Renglón de una lista. Necesita `product.prices.provider` y `price.provider` cargados.
     *
     * @return array<string, mixed>
     */
    public static function listItem(ShoppingListItem $item): array
    {
        $need = $item->needBaseQty();
        $stores = $item->product->latestPrices()
            ->map(fn (Price $p) => [
                'provider_id' => $p->provider_id,
                'provider' => $p->provider?->name,
                'price' => $p->price,
                'presentation' => $p->presentationLabel(),
                'cost_for_need' => $p->costFor($need),
            ])
            ->sortBy('cost_for_need')->values();

        return [
            'item_id' => $item->id,
            'product_id' => $item->product_id,
            'name' => $item->product->fullName(),
            'quantity' => $item->quantity,
            'presentation' => $item->product->sizeLabel(),
            'purchased' => $item->price_id !== null,
            'bought_at' => $item->price?->provider?->name,
            'paid' => $item->subtotal(),
            'estimate' => $item->price_id ? null : $stores->first()['cost_for_need'] ?? null,
            'cheapest_store_for_need' => $stores->first()['provider'] ?? null,
            'stores' => $stores->all(),
        ];
    }

    /**
     * Resumen de una lista. Necesita `items.price` e `items.product.prices` cargados.
     *
     * @return array<string, mixed>
     */
    public static function shoppingList(ShoppingList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'shopped_on' => $list->shopped_on->toDateString(),
            'notes' => $list->notes,
            'items' => $list->items->count(),
            'purchased' => $list->purchasedCount(),
            'total_paid' => round($list->total(), 2),
            'estimated_total' => round($list->estimatedTotal(), 2),
            'url' => route('lists.show', $list),
        ];
    }

    /** @return array<string, mixed> */
    public static function task(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'notes' => $task->notes,
            'list_id' => $task->task_list_id,
            'list' => $task->list?->name ?? 'General',
            'due_on' => $task->due_on?->toDateString(),
            'status' => $task->status(),
            'priority' => $task->priorityLabel(),
            'repeats' => $task->repeatLabel() ?: null,
            'completed_at' => $task->completed_at?->toDateTimeString(),
        ];
    }

    /**
     * Resumen de una receta. Necesita `ingredients.product.prices` cargado.
     *
     * @return array<string, mixed>
     */
    public static function recipe(Recipe $recipe): array
    {
        return [
            'id' => $recipe->id,
            'name' => $recipe->name,
            'category' => $recipe->category,
            'servings' => $recipe->servings,
            'minutes' => $recipe->minutes,
            'cost' => $recipe->cost(),
            'cost_per_serving' => $recipe->costPerServing(),
        ];
    }
}
