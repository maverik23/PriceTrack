<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Recipe;
use App\Models\ShoppingList;

/**
 * Pasa los ingredientes de recetas (una sola o el menú de la semana) a una
 * lista de compras, sumando lo que se necesita de cada artículo y descontando
 * lo que ya hay en la despensa.
 */
class MenuShopper
{
    /**
     * Lo que piden las recetas, por artículo, separado en lo que hay que comprar y lo
     * que cubre la despensa. `rows` trae, por artículo: lo que se necesita (`need`), lo que
     * cubre lo de casa (`have`) y lo que falta (`buy`), todo en unidad base.
     *
     * @param  iterable<int, array{0: Recipe, 1: float}>  $portions  receta + porciones a cocinar
     * @return array{needs: array<int, float>, buy: array<int, float>, rows: array<int, array{product: Product, need: float, have: float, buy: float}>, pantry: array<int, string>, unlinked: array<int, string>}
     */
    public function needs(iterable $portions): array
    {
        $needs = [];
        $unlinked = [];

        foreach ($portions as [$recipe, $servings]) {
            foreach ($recipe->shoppingNeeds($recipe->factor($servings)) as [$productId, $base, $name]) {
                if ($productId !== null && $base !== null) {
                    $needs[$productId] = ($needs[$productId] ?? 0) + $base;
                } else {
                    $unlinked[$name] = $name;
                }
            }
        }

        $products = Product::with('prices')->findMany(array_keys($needs))->keyBy('id');
        $rows = [];
        foreach ($needs as $productId => $base) {
            if (! $product = $products->get($productId)) {
                continue;
            }
            $have = $product->pantryCovers($base);
            // Menos de una milésima de lo que se necesita no amerita ir al súper
            $buy = $base - $have > $base * 0.001 ? $base - $have : 0.0;
            $rows[$productId] = ['product' => $product, 'need' => $base, 'have' => $have, 'buy' => $buy];
        }
        uasort($rows, fn ($a, $b) => [$a['buy'] > 0 ? 0 : 1, $a['product']->name] <=> [$b['buy'] > 0 ? 0 : 1, $b['product']->name]);

        return [
            'needs' => $needs,
            'buy' => array_map(fn ($row) => $row['buy'], array_filter($rows, fn ($row) => $row['buy'] > 0)),
            'rows' => $rows,
            // Lo que no hace falta comprar porque ya está en casa
            'pantry' => array_values(array_map(fn ($row) => $row['product']->name, array_filter($rows, fn ($row) => $row['buy'] <= 0))),
            'unlinked' => array_values($unlinked),
        ];
    }

    /**
     * Lo que se agrega según la revisión (los artículos marcados) y actualiza la despensa
     * con lo que se corrigió: desmarcar uno que faltaba = "lo tengo en casa"; marcar uno
     * que la despensa cubría = "ya se acabó" (se compra todo lo que se necesita).
     *
     * @param  array<int, int|string>  $checked  ids de los artículos marcados para comprar
     * @return array<int, float> id del artículo → unidad base a agregar
     */
    public function applyReview(array $result, array $checked): array
    {
        $checked = array_map('intval', $checked);
        $add = [];

        foreach ($result['rows'] as $productId => $row) {
            $wanted = $row['buy'] > 0;
            $isChecked = in_array((int) $productId, $checked, true);

            if ($isChecked && ! $wanted) {
                $row['product']->setPantry(false);
                $add[$productId] = $row['need'];
            } elseif ($isChecked) {
                $add[$productId] = $row['buy'];
            } elseif ($wanted) {
                // Sin cantidad: "tengo suficiente"
                $row['product']->setPantry(true);
            }
        }

        return $add;
    }

    /**
     * Lo que se agrega: lo que falta (sin revisión) o lo que se marcó en la revisión.
     *
     * @param  array<int, int|string>|null  $checked  null = sin revisión
     * @return array{0: array<int, float>, 1: array<int, string>} lo que se agrega y los artículos que no (están en casa)
     */
    public function chosen(array $result, ?array $checked): array
    {
        $add = $checked === null ? $result['buy'] : $this->applyReview($result, $checked);
        $skipped = array_values(array_map(fn ($row) => $row['product']->name, array_diff_key($result['rows'], $add)));

        return [$add, $skipped];
    }

    /**
     * Renglones para la pantalla de revisión, con el estimado de lo que se agregaría en la
     * tienda más barata para esa cantidad (paquetes completos o a granel).
     *
     * @return array<int, array<string, mixed>>
     */
    public function reviewRows(array $result): array
    {
        return array_values(array_map(function (array $row) {
            /** @var Product $product */
            $product = $row['product'];

            return [
                'id' => $product->id,
                'name' => $product->fullName(),
                'category' => $product->category,
                'url' => route('products.show', $product),
                'need' => $product->baseLabel($row['need']),
                'have' => $row['have'] > 0 ? ($product->pantry_qty === null ? 'suficiente' : $product->baseLabel($row['have'])) : null,
                'buy' => $row['buy'] > 0 ? $product->baseLabel($row['buy']) : null,
                'checked' => $row['buy'] > 0,
                'inPantry' => $product->inPantry(),
                // Si se marca: lo que falta; si la despensa lo cubría, todo lo que se necesita
                'estimate' => $product->cheapestCostFor($row['buy'] > 0 ? $row['buy'] : $row['need']),
                'buyLabel' => $product->baseLabel($row['buy'] > 0 ? $row['buy'] : $row['need']),
            ];
        }, $result['rows']));
    }

    /**
     * Suma las necesidades a la lista, en presentaciones habituales de cada artículo
     * (250 g de un artículo de 1 kg = 0.25). Devuelve cuántos artículos se tocaron.
     *
     * @param  array<int, float>  $needs
     */
    public function addToList(ShoppingList $list, array $needs): int
    {
        $products = Product::findMany(array_keys($needs))->keyBy('id');
        $count = 0;

        foreach ($needs as $productId => $base) {
            $product = $products->get($productId);
            if (! $product || $product->standardBaseQty() <= 0 || $base <= 0) {
                continue;
            }

            $item = $list->items()->firstOrNew(['product_id' => $productId]);
            $quantity = ($item->exists ? $item->quantity : 0) + $base / $product->standardBaseQty();
            $item->quantity = min(9999, max(0.0001, round($quantity, 4)));
            $item->save();
            $count++;
        }

        return $count;
    }

    /** La lista elegida o una nueva con ese nombre, para hoy. */
    public function resolveList(?int $listId, string $newName): ShoppingList
    {
        return $listId
            ? ShoppingList::findOrFail($listId)
            : ShoppingList::create(['name' => $newName, 'shopped_on' => today()]);
    }

    /**
     * Mensaje para el usuario con lo que se agregó, lo que ya estaba en casa y lo que no se pudo.
     *
     * @param  array<int, string>  $pantry  artículos que no se agregaron porque ya están en casa
     * @param  array<int, string>  $unlinked  ingredientes sin artículo
     */
    public function summary(int $count, array $pantry, array $unlinked): string
    {
        $message = match ($count) {
            0 => 'No se agregó nada a la lista.',
            1 => 'Se agregó 1 artículo a la lista.',
            default => "Se agregaron {$count} artículos a la lista.",
        };
        if ($pantry) {
            $message .= ' Ya tienes en casa: '.implode(', ', $pantry).'.';
        }
        if ($unlinked) {
            $message .= ' Sin artículo ligado (agrégalos a mano): '.implode(', ', $unlinked).'.';
        }

        return $message;
    }
}
