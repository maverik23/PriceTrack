<?php

namespace App\Models;

use App\Enums\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable(['name', 'brand', 'barcode', 'category', 'size', 'unit', 'notes'])]
class Product extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'unit' => Unit::class,
            'size' => 'float',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
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

        return static::distinctValues($column)->first(fn ($existing) => $key($existing) === $key($value)) ?? $value;
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
