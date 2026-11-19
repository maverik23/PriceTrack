<?php

namespace App\Models;

use App\Enums\Unit;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Receta. Un ingrediente puede ser un artículo del súper, texto libre u otra receta
 * (sub-receta); costo, macros, peso de la porción y lista de compras expanden las
 * sub-recetas. Toda la recursión lleva la pila de recetas visitadas ($stack) y un
 * límite de profundidad: un ciclo (que la validación impide) no se cuelga.
 */
#[Fillable(['name', 'category', 'servings', 'yield_qty', 'yield_unit', 'minutes', 'rating', 'source_url', 'notes'])]
class Recipe extends Model
{
    use HasAttachments;

    /** Niveles de sub-recetas que se expanden (receta → sub-receta → …). */
    public const MAX_DEPTH = 5;

    /** Relaciones para costear una receta con sus sub-recetas (las más profundas se cargan solas). */
    public const TREE = [
        'ingredients.product.prices',
        'ingredients.subrecipe.ingredients.product.prices',
        'ingredients.subrecipe.ingredients.subrecipe.ingredients.product.prices',
    ];

    protected function casts(): array
    {
        return [
            'servings' => 'integer',
            'minutes' => 'integer',
            'rating' => 'integer',
            'yield_qty' => 'float',
        ];
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('position')->orderBy('id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(RecipeStep::class)->orderBy('position')->orderBy('id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    public function planEntries(): HasMany
    {
        return $this->hasMany(MealPlanEntry::class);
    }

    /** Ingredientes de otras recetas que usan esta como sub-receta. */
    public function usedAsIngredient(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class, 'subrecipe_id');
    }

    /** Con todo lo necesario para costo, macros y lista de compras. */
    public function scopeWithTree(Builder $query): void
    {
        $query->with(self::TREE);
    }

    /** Carga lo necesario para costear y mandar a la lista (ingredientes, artículos y sub-recetas). */
    public function loadShoppingTree(): static
    {
        $this->loadMissing(self::TREE);

        return $this;
    }

    /** @return Collection<int, string> */
    public static function categories(): Collection
    {
        return static::query()
            ->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->orderBy('category')->pluck('category')->values();
    }

    /** Reusa la escritura existente de una categoría ("postres" → "Postres"). */
    public static function canonicalCategory(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($value === '') {
            return null;
        }
        $key = static fn (string $v) => Str::lower(Str::ascii($v));

        return static::categories()->first(fn ($candidate) => $key($candidate) === $key($value)) ?? $value;
    }

    /** Por cuánto se multiplican los ingredientes para cocinar $servings porciones. */
    public function factor(?float $servings = null): float
    {
        return $servings === null ? 1.0 : $servings / max(1, $this->servings);
    }

    // ---------- Rendimiento (para usarla como sub-receta por peso o volumen) ----------

    /** mass | volume, si la receta dice cuánto rinde. */
    public function yieldDimension(): ?string
    {
        $unit = $this->yield_qty ? Unit::tryFrom((string) $this->yield_unit) : null;

        return $unit && $unit->dimension() !== 'count' ? $unit->dimension() : null;
    }

    /** Lo que rinde en g o ml. */
    public function yieldBase(): ?float
    {
        return $this->yieldDimension() ? $this->yield_qty * Unit::from($this->yield_unit)->toBase() : null;
    }

    /** "1.2 kg", "750 ml". */
    public function yieldLabel(): ?string
    {
        return $this->yieldDimension() ? qty($this->yield_qty).' '.Unit::from($this->yield_unit)->label() : null;
    }

    // ---------- Costo ----------

    /**
     * Costo de lo que se usa (no de los paquetes completos), con el precio más
     * barato por unidad de cada artículo. Null si ningún ingrediente tiene precio.
     */
    public function cost(?float $servings = null): ?float
    {
        return $this->costAt($this->factor($servings));
    }

    /** @param  array<int, int>  $stack  recetas que se están expandiendo (contra ciclos) */
    public function costAt(float $factor, array $stack = [], bool $round = true): ?float
    {
        $stack[] = $this->id;
        $costs = $this->ingredients
            ->map(fn (RecipeIngredient $i) => $i->cost($factor, $stack, $round))
            ->filter(fn ($cost) => $cost !== null);

        return $costs->isEmpty() ? null : ($round ? round($costs->sum(), 2) : $costs->sum());
    }

    public function costPerServing(): ?float
    {
        $cost = $this->cost();

        return $cost === null ? null : round($cost / max(1, $this->servings), 2);
    }

    /** Ingredientes que no entran en el costo: sin artículo, sin cantidad o sin precio. */
    public function uncostedCount(): int
    {
        return $this->ingredients->filter(fn (RecipeIngredient $i) => $i->cost(1, [$this->id]) === null)->count();
    }

    // ---------- Nutrición y peso ----------

    /**
     * Macros de la receta completa ($servings porciones) con los ingredientes que tienen
     * datos de nutrición; `missing` = cuántos ingredientes ligados no los tienen.
     *
     * @return array{total: array<string, float>, perServing: array<string, float>, missing: int, counted: int}|null
     */
    public function nutrition(?float $servings = null): ?array
    {
        $servings ??= $this->servings;
        $totals = $this->nutritionAt($this->factor($servings));
        if (! $totals) {
            return null;
        }
        $total = array_map(fn ($v) => round($v, 1), $totals['total']);

        return [
            'total' => $total,
            'perServing' => array_map(fn ($v) => round($v / max(1, $servings), 1), $total),
            'missing' => $totals['missing'],
            'counted' => $totals['counted'],
        ];
    }

    /**
     * Totales sin redondear (para sumar sub-recetas).
     *
     * @return array{total: array<string, float>, missing: int, counted: int}|null
     */
    public function nutritionAt(float $factor, array $stack = []): ?array
    {
        $stack[] = $this->id;
        $total = array_fill_keys(array_keys(Product::NUTRIENTS), 0.0);
        $counted = 0;
        $missing = 0;

        foreach ($this->ingredients as $ingredient) {
            if ($ingredient->subrecipe_id) {
                $sub = $ingredient->subrecipeNutrition($factor, $stack);
                if ($sub) {
                    foreach ($sub['total'] as $key => $value) {
                        $total[$key] += $value;
                    }
                    $counted++;
                    $missing += $sub['missing'];
                } else {
                    $missing++;
                }

                continue;
            }

            $row = $ingredient->nutrition($factor);
            if ($row) {
                foreach ($row as $key => $value) {
                    $total[$key] += $value ?? 0;
                }
                $counted++;
            } elseif ($ingredient->product_id) {
                $missing++;
            }
        }

        return $counted ? ['total' => $total, 'missing' => $missing, 'counted' => $counted] : null;
    }

    /** Peso aproximado de una porción con lo que se puede pesar (null si casi nada). */
    public function gramsPerServing(): ?float
    {
        $grams = $this->gramsAt(1);

        return $grams === null ? null : round($grams / max(1, $this->servings));
    }

    /** Gramos de todo lo que se puede pesar, multiplicado por $factor. */
    public function gramsAt(float $factor, array $stack = []): ?float
    {
        $stack[] = $this->id;
        $grams = $this->ingredients->map(fn (RecipeIngredient $i) => $i->grams($factor, $stack))->filter(fn ($g) => $g !== null);

        return $grams->isEmpty() ? null : $grams->sum();
    }

    // ---------- Lista de compras ----------

    /**
     * Lo que pide la receta para la lista de compras: [id del artículo, unidad base, nombre]
     * por ingrediente, con las sub-recetas expandidas en sus ingredientes. Sin artículo
     * (o sin cantidad que se pueda convertir) el id y la cantidad son null.
     *
     * @return array<int, array{0: ?int, 1: ?float, 2: string}>
     */
    public function shoppingNeeds(float $factor = 1, array $stack = []): array
    {
        $stack[] = $this->id;
        $needs = [];

        foreach ($this->ingredients as $ingredient) {
            if ($ingredient->subrecipe_id) {
                $subFactor = $ingredient->subrecipeFactor($factor);
                $sub = $ingredient->subrecipe;
                if ($sub && $subFactor !== null && ! in_array($sub->id, $stack, true) && count($stack) < self::MAX_DEPTH) {
                    array_push($needs, ...$sub->shoppingNeeds($subFactor, $stack));
                } else {
                    $needs[] = [null, null, $ingredient->displayName()];
                }

                continue;
            }

            $base = $ingredient->productBaseQty($factor);
            $needs[] = [$base === null ? null : $ingredient->product_id, $base, $ingredient->displayName()];
        }

        return $needs;
    }

    // ---------- Sub-recetas: ciclos ----------

    /**
     * Ids de todas las recetas que esta usa, directa o indirectamente (sub-recetas de sub-recetas).
     *
     * @return array<int, int>
     */
    public static function descendantIds(int $recipeId): array
    {
        $seen = [];
        $frontier = [$recipeId];
        while ($frontier) {
            $next = RecipeIngredient::whereIn('recipe_id', $frontier)->whereNotNull('subrecipe_id')->pluck('subrecipe_id')->unique()->all();
            $frontier = array_values(array_diff($next, $seen, [$recipeId]));
            array_push($seen, ...$frontier);
        }

        return $seen;
    }

    // ---------- Presentación ----------

    /**
     * Ingredientes agrupados por sección, en el orden en que aparecen.
     *
     * @return array<int, array{section: ?string, ingredients: array<int, RecipeIngredient>}>
     */
    public function ingredientSections(): array
    {
        return self::groupConsecutive($this->ingredients, 'section', 'ingredients');
    }

    /**
     * Pasos agrupados por etapa, en orden.
     *
     * @return array<int, array{stage: ?string, steps: array<int, RecipeStep>}>
     */
    public function stepStages(): array
    {
        return self::groupConsecutive($this->steps, 'stage', 'steps');
    }

    /** Agrupa por una columna: los de la misma sección quedan juntos aunque no estén seguidos. */
    private static function groupConsecutive(Collection $items, string $column, string $key): array
    {
        $groups = [];
        foreach ($items as $item) {
            $name = filled($item->{$column}) ? $item->{$column} : null;
            $index = array_search($name, array_column($groups, $column), true);
            if ($index === false) {
                $groups[] = [$column => $name, $key => []];
                $index = array_key_last($groups);
            }
            $groups[$index][$key][] = $item;
        }

        return $groups;
    }

    /** Los pasos como texto, uno por línea con "# Etapa" (para quien todavía los lee así). */
    public function instructionsText(): string
    {
        $lines = [];
        foreach ($this->stepStages() as $group) {
            if ($group['stage']) {
                $lines[] = '# '.$group['stage'];
            }
            foreach ($group['steps'] as $step) {
                $lines[] = $step->body.($step->minutes ? " ({$step->minutes} min)" : '');
            }
        }

        return implode("\n", $lines);
    }
}
