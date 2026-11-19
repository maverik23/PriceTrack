<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'category', 'brand', 'model', 'serial_number', 'location', 'purchased_on', 'purchase_price',
    'warranty_until', 'notes', 'status', 'disposed_on', 'disposal_amount', 'valuation_method',
    'useful_life_years', 'salvage_value', 'annual_rate', 'meter_unit',
])]
class Asset extends Model
{
    use HasAttachments;

    public const STATUSES = ['active' => 'En uso', 'sold' => 'Vendido', 'retired' => 'Dado de baja'];

    public const METHODS = [
        'none' => 'Sin estimación (vale la última captura)',
        'straight_line' => 'Línea recta (vida útil y valor de rescate)',
        'annual_rate' => 'Porcentaje anual (− se deprecia, + plusvalía)',
    ];

    protected $attributes = ['status' => 'active', 'valuation_method' => 'none'];

    protected function casts(): array
    {
        return [
            'purchased_on' => 'date',
            'purchase_price' => 'float',
            'warranty_until' => 'date',
            'disposed_on' => 'date',
            'disposal_amount' => 'float',
            'useful_life_years' => 'float',
            'salvage_value' => 'float',
            'annual_rate' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // Los planes se borran en cascada en la BD; sus recordatorios pendientes, aquí
        static::deleting(function (Asset $asset) {
            $asset->removePendingMaintenanceTasks();
        });
    }

    public function valuations(): HasMany
    {
        return $this->hasMany(AssetValuation::class)->orderBy('valued_on')->orderBy('id');
    }

    public function meterReadings(): HasMany
    {
        return $this->hasMany(MeterReading::class)->orderBy('read_on')->orderBy('id');
    }

    public function maintenancePlans(): HasMany
    {
        return $this->hasMany(MaintenancePlan::class)->orderBy('name');
    }

    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(MaintenanceLog::class)->orderByDesc('done_on')->orderByDesc('id');
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    /** Enlace que muestran las tareas ligadas a este bien. */
    public function taskLink(): string
    {
        return route('assets.show', $this);
    }

    /** @return Collection<int, string> */
    public static function distinctValues(string $column): Collection
    {
        return static::query()
            ->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column)->values();
    }

    /** Reusa la escritura existente ("cocina" → "Cocina"), como en los artículos. */
    public static function canonicalValue(string $column, ?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($value === '') {
            return null;
        }
        $key = static fn (string $v) => Str::lower(Str::ascii($v));

        return static::distinctValues($column)->first(fn ($candidate) => $key($candidate) === $key($value)) ?? $value;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * Valor en una fecha: la última captura hasta ese día (o el precio de compra) ajustada
     * con el método del bien desde esa fecha. Null si no hay de dónde sacarlo o si aún no
     * se compraba; 0 desde que se vendió o se dio de baja.
     */
    public function valueOn(CarbonInterface $date): ?float
    {
        $date = CarbonImmutable::parse($date->toDateString());
        if ($this->purchased_on && $date->lt($this->purchased_on)) {
            return null;
        }
        if (! $this->isActive() && $this->disposed_on && $date->gte($this->disposed_on)) {
            return 0.0;
        }

        $valuation = $this->valuations->filter(fn (AssetValuation $v) => $v->valued_on->lte($date))->last();
        [$anchorDate, $anchorValue] = $valuation
            ? [$valuation->valued_on, (float) $valuation->value]
            : [$this->purchased_on, $this->purchase_price];
        if ($anchorValue === null) {
            return null;
        }

        $years = $anchorDate ? max(0, $anchorDate->diffInDays($date)) / 365.25 : 0;

        return round(match ($this->valuation_method) {
            'straight_line' => $this->straightLine($anchorValue, $years),
            'annual_rate' => $anchorValue * (1 + ($this->annual_rate ?? 0) / 100) ** $years,
            default => $anchorValue,
        }, 2);
    }

    /** Baja lo mismo cada año ((compra − rescate) ÷ vida útil) y no pasa del valor de rescate. */
    private function straightLine(float $anchorValue, float $years): float
    {
        $salvage = $this->salvage_value ?? 0;
        if (! $this->useful_life_years || $this->purchase_price === null) {
            return $anchorValue;
        }
        $perYear = ($this->purchase_price - $salvage) / $this->useful_life_years;

        return max(min($anchorValue, $salvage), $anchorValue - $perYear * $years);
    }

    public function currentValue(): ?float
    {
        return $this->valueOn(today());
    }

    /** Ganancia (o pérdida, negativa) contra el precio de compra; si se vendió, con lo que se recibió. */
    public function gain(): ?float
    {
        $value = $this->isActive() ? $this->currentValue() : $this->disposal_amount;

        return $value === null || $this->purchase_price === null ? null : round($value - $this->purchase_price, 2);
    }

    public function maintenanceCost(?int $year = null): float
    {
        return round($this->maintenanceLogs
            ->when($year, fn ($logs) => $logs->filter(fn (MaintenanceLog $l) => $l->done_on->year === $year))
            ->sum('cost'), 2);
    }

    /** Lo que ha costado tenerlo: compra + mantenimiento − lo que vale hoy (o lo que se recibió). */
    public function ownershipCost(): ?float
    {
        if ($this->purchase_price === null) {
            return null;
        }
        $value = $this->isActive() ? ($this->currentValue() ?? 0) : ($this->disposal_amount ?? 0);

        return round($this->purchase_price + $this->maintenanceCost() - $value, 2);
    }

    /** Lectura más alta del medidor (lecturas y servicios). */
    public function currentMeter(): ?float
    {
        $readings = $this->meterReadings->pluck('reading')
            ->merge($this->maintenanceLogs->pluck('meter_reading'))
            ->filter(fn ($v) => $v !== null);

        return $readings->isEmpty() ? null : (float) $readings->max();
    }

    public function warrantyActive(): bool
    {
        return $this->warranty_until !== null && $this->warranty_until->gte(today());
    }

    public function warrantyExpiresWithin(int $days): bool
    {
        return $this->warrantyActive() && $this->warranty_until->lte(today()->addDays($days));
    }

    /**
     * Puntos {x: fecha, y: valor} para la gráfica: cada mes (o cada varios, máximo ~120
     * puntos) desde la compra o la primera captura hasta hoy o la baja, más cada captura.
     *
     * @return array<int, array{x: string, y: float}>
     */
    public function valueSeries(): array
    {
        $start = $this->purchased_on ?? $this->valuations->first()?->valued_on;
        if (! $start) {
            return [];
        }
        $start = CarbonImmutable::parse($start->toDateString());
        $end = CarbonImmutable::today();
        if (! $this->isActive() && $this->disposed_on && $this->disposed_on->lt($end)) {
            $end = CarbonImmutable::parse($this->disposed_on->toDateString());
        }

        $step = max(1, (int) ceil($start->diffInMonths($end) / 120));
        $dates = collect();
        for ($d = $start; $d->lt($end); $d = $d->addMonthsNoOverflow($step)) {
            $dates->push($d);
        }
        $dates->push($end);
        $this->valuations->each(fn (AssetValuation $v) => $v->valued_on->between($start, $end) && $dates->push(CarbonImmutable::parse($v->valued_on->toDateString())));

        return $dates->unique(fn ($d) => $d->toDateString())->sort()->values()
            ->map(fn (CarbonImmutable $d) => ['x' => $d->toDateString(), 'y' => $this->valueOn($d)])
            ->filter(fn ($point) => $point['y'] !== null)->values()->all();
    }

    /** Quita los recordatorios pendientes de sus planes (al venderlo, darlo de baja o borrarlo). */
    public function removePendingMaintenanceTasks(): void
    {
        $taskIds = $this->maintenancePlans()->whereNotNull('task_id')->pluck('task_id');
        Task::whereIn('id', $taskIds)->pending()->delete();
    }

    /** Pone al día los recordatorios de todos sus planes. */
    public function syncMaintenanceTasks(): void
    {
        if (! $this->isActive()) {
            $this->removePendingMaintenanceTasks();

            return;
        }
        $this->maintenancePlans()->get()->each->syncTask();
    }
}
