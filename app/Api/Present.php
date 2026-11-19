<?php

namespace App\Api;

use App\Mcp\Present as Base;
use App\Models\Asset;
use App\Models\AssetValuation;
use App\Models\Attachment;
use App\Models\MaintenanceLog;
use App\Models\MaintenancePlan;
use App\Models\MealPlanEntry;
use App\Models\MeterReading;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductLink;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\ShoppingList;
use App\Models\Task;
use App\Models\TaskList;
use App\Support\AssetData;
use App\Support\RecipeData;
use App\Support\RecipeView;

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

    // ---------- Cocina ----------

    /**
     * Resumen de una receta. Necesita Recipe::TREE, `tags` y `attachments`.
     *
     * @param  array{ready: bool, missing: list<string>, unchecked: int}|null  $pantryCheck
     * @return array<string, mixed>
     */
    public static function recipe(Recipe $recipe, ?array $pantryCheck = null): array
    {
        return Base::recipe($recipe) + [
            'yield_qty' => $recipe->yield_qty,
            'yield_unit' => $recipe->yield_unit,
            'photo' => self::attachment($recipe->coverPhoto()),
            'pantry_check' => $pantryCheck,
        ];
    }

    /**
     * Ficha de la receta **a factor 1** (tal como está escrita, para `servings` porciones):
     * para otras porciones la app multiplica cantidades, costos y macros por
     * porciones ÷ `servings` (lo mismo que resources/js/recipe-show.js). Necesita lo que carga
     * Api\RecipeController::WITH.
     *
     * @return array<string, mixed>
     */
    public static function recipeDetail(Recipe $recipe): array
    {
        $view = RecipeView::data($recipe);
        $nutrition = $recipe->nutrition();

        return self::recipe($recipe, $recipe->ingredients->isEmpty() ? null : $recipe->pantryCheck()) + [
            'notes' => $recipe->notes,
            'source_url' => $recipe->source_url,
            // Sin redondear, para escalar
            'cost_exact' => $view['cost'],
            'uncosted' => $view['uncosted'],
            'nutrition' => $view['nutrition'],
            'nutrition_per_serving' => $nutrition['perServing'] ?? null,
            'grams_per_serving' => $recipe->gramsPerServing(),
            'units' => $view['units'],
            'sections' => array_map(fn (array $section) => [
                'name' => $section['name'],
                'items' => array_map(fn (array $item) => collect($item)->except('url')->all(), $section['items']),
            ], $view['sections']),
            // Todos los pasos en orden (modo cocinar) y agrupados por etapa
            'steps' => $view['steps'],
            'stages' => array_map(fn (array $stage) => [
                'name' => $stage['stage'],
                'steps' => array_map(fn (RecipeStep $s) => ['position' => $s->position, 'body' => $s->body, 'minutes' => $s->minutes], $stage['steps']),
            ], $recipe->stepStages()),
            'used_in' => $recipe->usedAsIngredient->map(fn (RecipeIngredient $i) => $i->recipe)->filter()->unique('id')
                ->map(fn (Recipe $r) => ['id' => $r->id, 'name' => $r->name])->values()->all(),
            'attachments' => $recipe->attachments->map(fn (Attachment $a) => self::attachment($a))->values()->all(),
            // Lo que acepta PUT recipes/{id} (para el formulario de edición)
            'input' => RecipeData::toInput($recipe),
        ];
    }

    /**
     * Un platillo del menú (de MenuWeek::summary()): costo de las porciones planeadas y
     * macros de una porción.
     *
     * @param  array{entry: MealPlanEntry, cost: ?float, macros: ?array}  $cell
     * @return array<string, mixed>
     */
    public static function mealEntry(array $cell): array
    {
        $entry = $cell['entry'];

        return [
            'id' => $entry->id,
            'date' => $entry->planned_on->toDateString(),
            'meal' => $entry->meal,
            'meal_label' => $entry->mealLabel(),
            'label' => $entry->label(),
            'recipe_id' => $entry->recipe_id,
            'title' => $entry->title,
            'servings' => $entry->plannedServings(),
            'cost' => $cell['cost'],
            'macros' => $cell['macros'],
            'photo' => self::attachment($entry->recipe?->coverPhoto()),
        ];
    }

    // ---------- Hogar ----------

    /**
     * Una tarea. `taskable` dice a qué está ligada (un bien, por su mantenimiento).
     * Necesita `list` y `taskable`.
     *
     * @return array<string, mixed>
     */
    public static function task(Task $task): array
    {
        return Base::task($task) + [
            'priority_value' => $task->priority,
            'repeat_every' => $task->repeat_every,
            'repeat_unit' => $task->repeat_unit,
            'repeated_from_id' => $task->repeated_from_id,
            'taskable' => $task->taskable ? [
                'type' => $task->taskable instanceof Asset ? 'asset' : class_basename($task->taskable),
                'id' => $task->taskable->getKey(),
                'name' => $task->taskable->name ?? null,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function taskList(TaskList $list): array
    {
        return ['id' => $list->id, 'name' => $list->name, 'pending' => $list->tasks_count ?? null];
    }

    /**
     * Un plan de mantenimiento: cada cuánto, cuándo toca (fecha y/o lectura) y su estado
     * (`overdue`, `soon`, `ok`, `none` = sin intervalo). Necesita `logs` y `asset`.
     *
     * @return array<string, mixed>
     */
    public static function plan(MaintenancePlan $plan): array
    {
        return [
            'id' => $plan->id,
            'asset_id' => $plan->asset_id,
            'asset' => $plan->asset?->name,
            'name' => $plan->name,
            'every' => $plan->every,
            'every_unit' => $plan->every_unit,
            'every_meter' => $plan->every_meter,
            'interval' => $plan->intervalLabel(),
            'estimated_cost' => $plan->estimated_cost,
            'notes' => $plan->notes,
            'status' => $plan->status(),
            'next_due_on' => $plan->nextDueOn()?->toDateString(),
            'next_meter' => $plan->nextMeter(),
            'due_label' => $plan->dueLabel() ?: null,
            'last_done_on' => $plan->lastLog()?->done_on?->toDateString(),
            'task_id' => $plan->task_id,
        ];
    }

    /**
     * Un bien en la lista: datos, valor de hoy, ganancia contra la compra, medidor, garantía y
     * sus planes. Necesita lo de AssetReport::assets() y `attachments`.
     *
     * @return array<string, mixed>
     */
    public static function asset(Asset $asset): array
    {
        return [
            ...$asset->only(['id', 'name', 'category', 'brand', 'model', 'serial_number', 'location', 'purchase_price', 'status',
                'disposal_amount', 'valuation_method', 'useful_life_years', 'salvage_value', 'annual_rate', 'meter_unit', 'notes']),
            'status_label' => $asset->statusLabel(),
            'purchased_on' => $asset->purchased_on?->toDateString(),
            'warranty_until' => $asset->warranty_until?->toDateString(),
            'warranty_active' => $asset->warrantyActive(),
            'disposed_on' => $asset->disposed_on?->toDateString(),
            'current_value' => $asset->isActive() ? $asset->currentValue() : null,
            'gain' => $asset->gain(),
            'current_meter' => $asset->currentMeter(),
            'maintenance_cost_year' => $asset->maintenanceCost(today()->year),
            'maintenance_cost_total' => $asset->maintenanceCost(),
            'ownership_cost' => $asset->ownershipCost(),
            'photo' => self::attachment($asset->coverPhoto()),
            'plans' => $asset->maintenancePlans->map(fn (MaintenancePlan $p) => self::plan($p->setRelation('asset', $asset)))->values()->all(),
        ];
    }

    /**
     * Ficha de un bien: además, todo su historial (valores, lecturas, servicios, con su `id`
     * para borrarlos), la serie de su valor para la gráfica, sus tareas pendientes y sus fotos
     * y documentos. Necesita lo de Api\AssetController::WITH.
     *
     * @return array<string, mixed>
     */
    public static function assetDetail(Asset $asset): array
    {
        return self::asset($asset) + [
            'valuations' => $asset->valuations->sortByDesc('valued_on')->map(fn (AssetValuation $v) => [
                'id' => $v->id, 'valued_on' => $v->valued_on->toDateString(), 'value' => $v->value, 'notes' => $v->notes,
            ])->values()->all(),
            'readings' => $asset->meterReadings->sortByDesc('read_on')->map(fn (MeterReading $r) => [
                'id' => $r->id, 'read_on' => $r->read_on->toDateString(), 'reading' => $r->reading,
            ])->values()->all(),
            'services' => $asset->maintenanceLogs->sortByDesc('done_on')->map(fn (MaintenanceLog $l) => [
                'id' => $l->id, 'done_on' => $l->done_on->toDateString(), 'plan_id' => $l->maintenance_plan_id, 'plan' => $l->plan?->name,
                'meter_reading' => $l->meter_reading, 'cost' => $l->cost, 'provider' => $l->provider, 'notes' => $l->notes, 'task_id' => $l->task_id,
            ])->values()->all(),
            'series' => $asset->valueSeries(),
            'tasks' => $asset->tasks()->with('list')->pending()->orderByRaw('due_on is null')->orderBy('due_on')->get()
                ->map(fn (Task $t) => Base::task($t))->all(),
            'attachments' => $asset->attachments->map(fn (Attachment $a) => self::attachment($a))->values()->all(),
            // Lo que acepta PUT assets/{id} (para el formulario de edición)
            'input' => AssetData::toInput($asset),
        ];
    }
}
