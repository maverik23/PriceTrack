<?php

namespace App\Models;

use App\Enums\IngredientUnit;
use App\Enums\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable(['name', 'brand', 'barcode', 'category', 'size', 'unit', 'notes', 'piece_weight', 'cup_grams', 'kcal', 'protein', 'carbs', 'fat'])]
class Product extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'unit' => Unit::class,
            'size' => 'float',
            'piece_weight' => 'float',
            'cup_grams' => 'float',
            'kcal' => 'float',
            'protein' => 'float',
            'carbs' => 'float',
            'fat' => 'float',
        ];
    }

    /** Campos de nutrición: por 100 g o 100 ml, o por pieza si el artículo se mide en piezas. */
    public const NUTRIENTS = ['kcal' => 'kcal', 'protein' => 'Proteína', 'carbs' => 'Carbohidratos', 'fat' => 'Grasa'];

    /**
     * Pasa la cantidad de un ingrediente a la unidad base del artículo (g, ml o pza).
     * Entre dimensiones usa el peso de una taza (volumen ↔ peso) y el de una pieza
     * (piezas ↔ peso o volumen). Null si falta ese dato.
     */
    public function fromIngredient(IngredientUnit $unit, float $quantity): ?float
    {
        $amount = $quantity * $unit->toBase();
        $from = $unit->dimension();
        $to = $this->unit->dimension();
        $gramsPerMl = $this->cup_grams ? $this->cup_grams / IngredientUnit::CUP_ML : null;

        return match (true) {
            $from === $to => $amount,
            $from === 'volume' && $to === 'mass' => $gramsPerMl ? $amount * $gramsPerMl : null,
            $from === 'mass' && $to === 'volume' => $gramsPerMl ? $amount / $gramsPerMl : null,
            $from === 'count' => $this->piece_weight ? $amount * $this->piece_weight : null,
            $to === 'count' && $from === 'mass' => $this->piece_weight ? $amount / $this->piece_weight : null,
            $to === 'count' && $from === 'volume' => $this->piece_weight && $gramsPerMl ? $amount * $gramsPerMl / $this->piece_weight : null,
            default => null,
        };
    }

    /** Qué dato le falta para usar esa unidad ("cuánto pesa una taza"), o null si se puede. */
    public function missingConversion(IngredientUnit $unit): ?string
    {
        if ($this->fromIngredient($unit, 1) !== null) {
            return null;
        }

        return $unit->dimension() === 'count' || $this->unit->dimension() === 'count'
            ? 'cuánto pesa una pieza'
            : 'cuánto pesa una taza';
    }

    public function hasNutrition(): bool
    {
        return $this->kcal !== null || $this->protein !== null || $this->carbs !== null || $this->fat !== null;
    }

    /** Gramos aproximados de una cantidad en la unidad base (para el tamaño de la porción). */
    public function gramsOf(float $baseQty): ?float
    {
        return match ($this->unit->dimension()) {
            'mass' => $baseQty,
            'volume' => $baseQty * ($this->cup_grams ? $this->cup_grams / IngredientUnit::CUP_ML : 1),
            'count' => $this->piece_weight ? $baseQty * $this->piece_weight : null,
        };
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    /** El artículo en cada tienda: marca, código y página para consultar su precio. */
    public function links(): HasMany
    {
        return $this->hasMany(ProductLink::class);
    }

    /** Artículo con ese código de barras, propio o de su versión en alguna tienda. */
    public static function findByBarcode(string $barcode): ?self
    {
        return static::where('barcode', $barcode)
            ->orWhereHas('links', fn ($q) => $q->where('barcode', $barcode))
            ->orderByRaw('barcode = ? desc', [$barcode])
            ->first();
    }

    public function listItems(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class);
    }

    /**
     * Valores distintos ya usados en una columna de texto libre (marca, categoría).
     *
     * @return Collection<int, string>
     */
    public static function distinctValues(string $column): Collection
    {
        return static::query()
            ->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column)->values();
    }

    /**
     * Si el valor ya existe (sin importar mayúsculas, acentos ni espacios dobles)
     * devuelve la escritura existente; si no, el valor limpio como dato nuevo.
     */
    public static function canonicalValue(string $column, ?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($value === '') {
            return null;
        }

        $key = static fn (string $v) => Str::lower(Str::ascii($v));
        $existing = static::distinctValues($column);
        if ($column === 'brand') {
            // También las marcas de la versión del artículo en cada tienda ("LALA" → "Lala")
            $existing = $existing->merge(ProductLink::whereNotNull('brand')->where('brand', '!=', '')->distinct()->orderBy('brand')->pluck('brand'));
        }

        return $existing->first(fn ($candidate) => $key($candidate) === $key($value)) ?? $value;
    }

    public function sizeLabel(): string
    {
        return qty($this->size).' '.$this->unit->label();
    }

    /** Contenido de la presentación habitual en unidad base (ml, g, pza). */
    public function standardBaseQty(): float
    {
        return $this->size * $this->unit->toBase();
    }

    public function fullName(): string
    {
        return trim($this->name.($this->brand ? ' · '.$this->brand : ''));
    }

    /**
     * Último precio registrado en cada proveedor, ordenado del más barato
     * (por unidad) al más caro.
     *
     * @return Collection<int, Price>
     */
    public function latestPrices(): Collection
    {
        return $this->prices
            ->sortByDesc(fn (Price $p) => $p->observed_on->format('Ymd').str_pad((string) $p->id, 10, '0', STR_PAD_LEFT))
            ->unique('provider_id')
            ->sortBy('unit_price')
            ->values();
    }

    public function cheapestPrice(): ?Price
    {
        return $this->latestPrices()->first();
    }
}
