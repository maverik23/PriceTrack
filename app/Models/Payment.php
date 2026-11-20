<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Un pago hecho: de un pago recurrente (con la fecha que tocaba y, si lleva medidor, la lectura
 * final del periodo; guarda su nombre y categoría), suelto a una deuda (abono) o **único**
 * (gasolina, una comida fuera: sin recurrente ni deuda, con su concepto y categoría).
 */
#[Fillable(['recurring_payment_id', 'debt_id', 'name', 'category', 'paid_on', 'amount', 'reading', 'due_on', 'notes'])]
class Payment extends Model
{
    /** recurring = de un pago recurrente, debt = abono suelto a una deuda, one_time = pago único. */
    public const KINDS = ['recurring' => 'Recurrente', 'one_time' => 'Único', 'debt' => 'Abono a deuda'];

    /** Sugerencias de categoría la primera vez (luego se reusan las escritas). */
    public const SUGGESTED_CATEGORIES = ['Servicios', 'Gasolina', 'Comida fuera', 'Transporte', 'Salud', 'Entretenimiento', 'Ropa', 'Hogar', 'Educación', 'Suscripciones'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'due_on' => 'date', 'amount' => 'float', 'reading' => 'float'];
    }

    public function recurringPayment(): BelongsTo
    {
        return $this->belongsTo(RecurringPayment::class);
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    /** Del periodo (rango semiabierto: la fecha puede guardarse con hora). */
    public function scopeBetweenDates(Builder $query, \DateTimeInterface $from, \DateTimeInterface $until): void
    {
        $query->where('paid_on', '>=', $from->format('Y-m-d'))->where('paid_on', '<', $until->format('Y-m-d'));
    }

    /** Los pagos únicos: ni de un recurrente ni abono a una deuda. */
    public function scopeOneTime(Builder $query): void
    {
        $query->whereNull('recurring_payment_id')->whereNull('debt_id');
    }

    /** recurring | debt | one_time (ver KINDS). */
    public function kind(): string
    {
        return match (true) {
            $this->recurring_payment_id !== null => 'recurring',
            $this->debt_id !== null => 'debt',
            default => 'one_time',
        };
    }

    public function isOneTime(): bool
    {
        return $this->kind() === 'one_time';
    }

    /** El concepto: el escrito, el del recurrente o "Abono a {deuda}". */
    public function label(): string
    {
        return $this->name
            ?? $this->recurringPayment?->name
            ?? ($this->debt ? 'Abono a '.$this->debt->name : 'Pago');
    }

    /** La categoría: la guardada o la del recurrente; los abonos van a "Deudas". */
    public function categoryLabel(): string
    {
        return $this->category ?? $this->recurringPayment?->category ?? ($this->debt_id ? 'Deudas' : 'Sin categoría');
    }

    /**
     * Categorías ya usadas (en pagos y pagos recurrentes) y, si no hay, las sugeridas.
     *
     * @return Collection<int, string>
     */
    public static function categories(): Collection
    {
        $used = static::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category')
            ->merge(RecurringPayment::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category'))
            ->unique(fn ($c) => Str::lower(Str::ascii($c)))->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();

        return $used->isEmpty() ? collect(self::SUGGESTED_CATEGORIES) : $used;
    }

    /** Reusa la escritura existente ("comida fuera" → "Comida fuera"). */
    public static function canonicalCategory(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($value === '') {
            return null;
        }
        $key = static fn (string $v) => Str::lower(Str::ascii($v));

        return static::categories()->merge(self::SUGGESTED_CATEGORIES)->first(fn ($c) => $key($c) === $key($value)) ?? $value;
    }

    /** El pago anterior del mismo recurrente que tiene lectura (por la fecha que tocaba). */
    public function previousWithReading(): ?self
    {
        if (! $this->recurring_payment_id || $this->reading === null) {
            return null;
        }

        return static::where('recurring_payment_id', $this->recurring_payment_id)
            ->whereKeyNot($this->id)->whereNotNull('reading')
            ->where(fn ($q) => $q->where('due_on', '<', ($this->due_on ?? $this->paid_on)->toDateString())
                ->orWhere(fn ($q) => $q->where('due_on', ($this->due_on ?? $this->paid_on)->toDateString())->where('id', '<', $this->id)))
            ->orderByDesc('due_on')->orderByDesc('id')
            ->first();
    }

    /**
     * Consumo del periodo: esta lectura − la anterior (null sin lectura anterior o si el medidor
     * se reinició). `$previous` evita la consulta cuando ya se tiene.
     */
    public function consumption(?self $previous = null): ?float
    {
        $previous ??= $this->previousWithReading();
        if ($this->reading === null || $previous?->reading === null || $this->reading < $previous->reading) {
            return null;
        }

        return round($this->reading - $previous->reading, 3);
    }

    /** Lo que costó cada unidad consumida ($ por m³, por kWh). */
    public function unitPrice(?self $previous = null): ?float
    {
        $used = $this->consumption($previous);

        return $used > 0 ? round($this->amount / $used, 4) : null;
    }
}
