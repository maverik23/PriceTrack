<?php

namespace App\Api;

use App\Models\Price;
use App\Models\Provider;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Support\ListGrid;
use Illuminate\Support\Collection;

/**
 * La hoja de una lista para la app móvil, ya calculada: lo que en la web hace en vivo
 * resources/js/list-grid.js (tiendas visibles, el más barato por unidad, dónde conviene
 * llevarse lo pedido, estimados, totales por tienda y las tarjetas de ahorro) sale aquí de
 * los mismos modelos (Price::costFor(), ShoppingListItem::subtotal(), ShoppingList::paidByProvider()).
 * **Si cambias un cálculo en list-grid.js, cambia el de aquí.**
 */
class ListSheet
{
    /** Relaciones que necesita. */
    public const WITH = ['items.product.prices', 'items.product.links', 'items.product.attachments', 'items.price', 'tickets'];

    /** @return array<string, mixed> */
    public static function data(ShoppingList $list): array
    {
        $list->load(self::WITH);
        $items = $list->items->sortBy('id')->values();
        $providers = Provider::orderBy('name')->get();
        $paid = $list->paidByProvider();
        $added = array_map('intval', $list->provider_columns['added'] ?? []);
        $hidden = array_map('intval', $list->provider_columns['hidden'] ?? []);

        // Último precio de cada tienda por renglón: [item_id => [provider_id => Price]]
        $cells = $items->mapWithKeys(fn (ShoppingListItem $item) => [
            $item->id => $item->product->latestPrices()->keyBy('provider_id'),
        ]);
        $bought = $items->filter(fn (ShoppingListItem $i) => $i->price)->countBy(fn (ShoppingListItem $i) => $i->price->provider_id);

        // Se ve la tienda con datos en la lista (o agregada a mano) que no se ocultó; donde se compró, siempre
        $hasData = fn (int $pid) => isset($paid[$pid]['ticket'])
            || $items->contains(fn (ShoppingListItem $i) => $cells[$i->id]->has($pid)
                || $i->product->links->contains(fn ($l) => $l->provider_id === $pid && $l->url));
        $visible = $providers->filter(fn (Provider $p) => ($bought[$p->id] ?? 0) > 0
            || (! in_array($p->id, $hidden, true) && (in_array($p->id, $added, true) || $hasData($p->id))))
            ->pluck('id')->all();

        $rows = $items->map(fn (ShoppingListItem $item) => self::row($item, $list, $cells[$item->id], $visible));

        $baskets = collect($visible)->mapWithKeys(function (int $pid) use ($rows) {
            $costs = $rows->map(fn (array $r) => collect($r['cells'])->firstWhere('provider_id', $pid)['cost_for_need'] ?? null)->filter(fn ($c) => $c !== null);

            return [$pid => ['covered' => $costs->count(), 'total' => round($costs->sum(), 2)]];
        });
        // "Si compras todo aquí": la más barata entre las que tienen todos los artículos
        $full = $baskets->filter(fn (array $b) => $b['covered'] > 0 && $b['covered'] === $rows->count());
        $bestBasket = $full->count() > 1 ? $full->sortBy('total')->keys()->first() : null;

        $marked = round($rows->sum(fn (array $r) => $r['subtotal'] ?? 0), 2);
        $total = round($list->total(), 2);
        $purchased = $rows->filter(fn (array $r) => $r['price_id']);
        $cheapestTotal = round($purchased->sum(fn (array $r) => $r['best_buy']['cost'] ?? $r['subtotal'] ?? 0), 2);

        return [
            'list' => [
                'id' => $list->id,
                'name' => $list->name,
                'shopped_on' => $list->shopped_on->toDateString(),
                'notes' => $list->notes,
                'done' => $list->isDone(),
                'price_date' => $list->priceDate()->toDateString(),
            ],
            'provider_columns' => ['added' => $added, 'hidden' => $hidden],
            'providers' => $providers->map(fn (Provider $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'visible' => in_array($p->id, $visible, true),
                'can_hide' => ($bought[$p->id] ?? 0) === 0,
                'bought_count' => $bought[$p->id] ?? 0,
                // Lo marcado ahí, el ticket capturado (null = sin ticket) y lo pagado (el ticket o la suma)
                'bought_total' => $paid[$p->id]['computed'] ?? 0.0,
                'ticket' => $paid[$p->id]['ticket'] ?? null,
                'paid' => $paid[$p->id]['paid'] ?? 0.0,
                'basket' => $baskets[$p->id] ?? null,
                'best_basket' => $bestBasket === $p->id,
            ])->values()->all(),
            'rows' => $rows->all(),
            'summary' => [
                'items' => $rows->count(),
                'purchased' => $purchased->count(),
                'total_paid' => $total,
                'total_marked' => $marked,
                'total_estimated' => round($total + $rows->sum(fn (array $r) => $r['price_id'] ? 0 : ($r['estimated'] ?? 0)), 2),
                // Lo que costaba lo comprado en la tienda más cara − lo pagado
                'real_savings' => round($purchased->sum(fn (array $r) => max([$r['subtotal'], ...array_column($r['cells'], 'cost_for_need')]) - $r['subtotal']), 2),
                // Lo comprado, cada artículo en la tienda más barata para su cantidad
                'cheapest_total' => $cheapestTotal,
                'possible_savings' => round(max(0, $marked - $cheapestTotal), 2),
            ],
        ];
    }

    /**
     * @param  Collection<int, Price>  $latest  último precio por tienda (provider_id => Price)
     * @param  list<int>  $visible
     * @return array<string, mixed>
     */
    public static function row(ShoppingListItem $item, ShoppingList $list, Collection $latest, array $visible): array
    {
        $product = $item->product;
        $need = $item->needBaseQty();
        // Las tiendas ocultas no cuentan para "el más barato"
        $shown = $latest->filter(fn (Price $p) => in_array($p->provider_id, $visible, true));
        $cheapest = $shown->sortBy('unit_price')->first();
        $best = $shown->sortBy(fn (Price $p) => $p->costFor($need))->first();
        $several = $shown->count() > 1;

        $units = $item->boughtUnits();

        return [
            'id' => $item->id,
            'product' => ListGrid::product($product) + ['photo' => Present::attachment($product->coverPhoto())],
            'quantity' => $item->quantity,
            'need_base' => round($need, 3),
            // Lo pedido en contenido: "1.8 kg"
            'need_label' => $product->baseLabel($need),
            'price_id' => $item->price_id,
            'bought_qty' => $item->bought_qty,
            'bought_units' => $units === null ? null : round($units, 4),
            // Lo comprado, corto: "3 × 900 g" o, a granel, "1.2 kg"
            'bought_label' => self::boughtLabel($item, $units),
            'subtotal' => $item->subtotal(),
            'estimated' => $best?->costFor($need),
            'best_buy' => $best ? ['provider_id' => $best->provider_id, 'cost' => $best->costFor($need)] : null,
            'selected' => ListGrid::price($item->price, $list),
            'cells' => $shown->sortBy('provider_id')->map(fn (Price $p) => ListGrid::price($p, $list) + [
                'cost_for_need' => $p->costFor($need),
                // Paquetes completos que cubren lo pedido (null a granel: se lleva lo justo)
                'packs' => $p->bulk ? null : $p->packsFor($need),
                'compare_price' => round($p->comparePrice(), 2),
                'compare_label' => $p->unit->compareLabel(),
                'cheapest' => $several && $cheapest?->id === $p->id,
                'best_buy' => $several && $best?->id === $p->id,
                'diff' => $cheapest && $cheapest->id !== $p->id && $cheapest->unit_price > 0
                    ? (int) round(($p->unit_price / $cheapest->unit_price - 1) * 100) : null,
                'bought' => $item->price?->provider_id === $p->provider_id,
            ])->values()->all(),
            'links' => (object) $product->links->whereNotNull('url')->mapWithKeys(fn ($l) => [$l->provider_id => ['id' => $l->id, 'url' => $l->url]])->all(),
            'stores' => (object) $product->links->mapWithKeys(fn ($link) => [
                $link->provider_id => ['brand' => $link->brand, 'name' => $link->store_name, 'barcode' => $link->barcode],
            ])->all(),
        ];
    }

    private static function boughtLabel(ShoppingListItem $item, ?float $units): ?string
    {
        if ($units === null) {
            return null;
        }
        $price = $item->price;
        if ($price->bulk) {
            return $item->product->baseLabel($units * $price->base_qty);
        }

        return $units == 1.0 ? $price->presentationLabel() : qty($units).' × '.$price->presentationLabel();
    }
}
