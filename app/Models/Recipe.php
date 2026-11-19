<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable(['name', 'category', 'servings', 'minutes', 'instructions', 'source_url', 'notes'])]
class Recipe extends Model
{
    use HasAttachments;

    protected function casts(): array
    {
        return [
            'servings' => 'integer',
            'minutes' => 'integer',
        ];
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('position')->orderBy('id');
    }

    public function planEntries(): HasMany
    {
        return $this->hasMany(MealPlanEntry::class);
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

    /**
     * Costo de lo que se usa (no de los paquetes completos), con el precio más
     * barato por unidad de cada artículo. Null si ningún ingrediente tiene precio.
     */
    public function cost(?float $servings = null): ?float
    {
        $costs = $this->ingredients
            ->map(fn (RecipeIngredient $i) => $i->cost($this->factor($servings)))
            ->filter(fn ($cost) => $cost !== null);

        return $costs->isEmpty() ? null : round($costs->sum(), 2);
    }

    public function costPerServing(): ?float
    {
        $cost = $this->cost();

        return $cost === null ? null : round($cost / max(1, $this->servings), 2);
    }

    /** Ingredientes que no entran en el costo: sin artículo, sin cantidad o sin precio. */
    public function uncostedCount(): int
    {
        return $this->ingredients->filter(fn (RecipeIngredient $i) => $i->cost() === null)->count();
    }

    /**
     * Macros de la receta completa ($servings porciones) con los ingredientes que tienen
     * datos de nutrición; `missing` = cuántos ingredientes ligados no los tienen.
     *
     * @return array{total: array<string, float>, perServing: array<string, float>, missing: int, counted: int}|null
     */
    public function nutrition(?float $servings = null): ?array
    {
        $servings ??= $this->servings;
        $factor = $this->factor($servings);
        $rows = $this->ingredients->map(fn (RecipeIngredient $i) => $i->nutrition($factor));
        $counted = $rows->filter();
        if ($counted->isEmpty()) {
            return null;
        }

        $total = collect(Product::NUTRIENTS)->map(fn ($label, $key) => round($counted->sum(fn ($n) => $n[$key] ?? 0), 1))->all();

        return [
            'total' => $total,
            'perServing' => array_map(fn ($v) => round($v / max(1, $servings), 1), $total),
            'missing' => $this->ingredients->filter(fn (RecipeIngredient $i) => $i->product_id && $i->nutrition() === null)->count(),
            'counted' => $counted->count(),
        ];
    }

    /** Peso aproximado de una porción con lo que se puede pesar (null si casi nada). */
    public function gramsPerServing(): ?float
    {
        $grams = $this->ingredients->map(fn (RecipeIngredient $i) => $i->grams())->filter(fn ($g) => $g !== null);

        return $grams->isEmpty() ? null : round($grams->sum() / max(1, $this->servings));
    }

    /** Líneas de las instrucciones, una por paso. */
    public function steps(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $this->instructions))));
    }
}
