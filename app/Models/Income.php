<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Un ingreso recibido: de una recurrente (sueldo, vales del mes) o eventual (bono, venta,
 * regalo). `kind`: dinero o en especie (vales de despensa o gasolina, un regalo), con su
 * valor en pesos en `amount`.
 */
#[Fillable(['income_source_id', 'received_on', 'due_on', 'name', 'category', 'kind', 'amount', 'notes'])]
class Income extends Model
{
    public const KINDS = ['money' => 'Dinero', 'in_kind' => 'En especie'];

    /** Sugerencias de categoría la primera vez (luego se reusan las escritas). */
    public const SUGGESTED_CATEGORIES = ['Sueldo', 'Aguinaldo', 'Bono', 'Vales de despensa', 'Vales de gasolina', 'Regalo', 'Venta', 'Renta', 'Intereses', 'Reembolso'];

    protected $attributes = ['kind' => 'money'];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'due_on' => 'date',
            'amount' => 'float',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(IncomeSource::class, 'income_source_id');
    }

    /** Del mes (rango semiabierto: la fecha puede guardarse con hora). */
    public function scopeBetweenDates(Builder $query, \DateTimeInterface $from, \DateTimeInterface $until): void
    {
        $query->where('received_on', '>=', $from->format('Y-m-d'))->where('received_on', '<', $until->format('Y-m-d'));
    }

    public function isInKind(): bool
    {
        return $this->kind === 'in_kind';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? 'Dinero';
    }

    /** Categorías ya usadas (en ingresos y recurrentes) y, si no hay, las sugeridas. @return Collection<int, string> */
    public static function categories(): Collection
    {
        $used = static::query()->whereNotNull('category')->distinct()->pluck('category')
            ->merge(IncomeSource::whereNotNull('category')->distinct()->pluck('category'))
            ->filter()->unique()->sort()->values();

        return $used->isEmpty() ? collect(self::SUGGESTED_CATEGORIES) : $used;
    }

    /** Reusa la escritura existente ("vales de despensa" → "Vales de despensa"). */
    public static function canonicalCategory(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($value === '') {
            return null;
        }
        $key = static fn (string $v) => Str::lower(Str::ascii($v));

        return static::categories()->merge(self::SUGGESTED_CATEGORIES)->first(fn ($c) => $key($c) === $key($value)) ?? $value;
    }
}
