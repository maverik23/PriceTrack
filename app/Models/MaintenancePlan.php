<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Qué se le hace a un bien y cada cuánto: por tiempo (cada 6 meses) y/o por uso
 * (cada 10,000 km). Lo que toque primero manda. Mantiene una tarea pendiente con la
 * fecha del próximo servicio; completarla registra el servicio.
 */
#[Fillable(['asset_id', 'name', 'every', 'every_unit', 'every_meter', 'estimated_cost', 'notes'])]
class MaintenancePlan extends Model
{
    /** Días antes de la fecha (o fracción del intervalo de uso) en que se marca "pronto". */
    public const SOON_DAYS = 30;

    public const SOON_METER = 0.1;

    /** Lista de tareas donde quedan los recordatorios. */
    public const TASK_LIST = 'Mantenimiento';

    protected function casts(): array
    {
        return [
            'every' => 'integer',
            'every_meter' => 'float',
            'estimated_cost' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (MaintenancePlan $plan) {
            $plan->task()->pending()->delete();
        });
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MaintenanceLog::class)->orderByDesc('done_on')->orderByDesc('id');
    }

    public function lastLog(): ?MaintenanceLog
    {
        return $this->logs->first();
    }

    public function hasTimeInterval(): bool
    {
        return $this->every > 0 && isset(Task::REPEAT_UNITS[$this->every_unit]);
    }

    /** "Cada 6 meses o 10,000 km". */
    public function intervalLabel(): string
    {
        $parts = [];
        if ($this->hasTimeInterval()) {
            [$one, $many] = Task::REPEAT_UNITS[$this->every_unit];
            $parts[] = $this->every === 1 ? $one : "{$this->every} {$many}";
        }
        if ($this->every_meter) {
            $parts[] = number_format($this->every_meter).' '.$this->asset->meter_unit;
        }

        return $parts ? 'Cada '.implode(' o ', $parts) : 'Sin intervalo';
    }

    /** Desde la fecha del último servicio (o la compra del bien, o cuando se creó el plan). */
    public function nextDueOn(): ?CarbonImmutable
    {
        if (! $this->hasTimeInterval()) {
            return null;
        }
        $from = $this->lastLog()?->done_on ?? $this->asset->purchased_on ?? $this->created_at ?? now();
        $from = CarbonImmutable::parse($from->toDateString());

        return match ($this->every_unit) {
            'day' => $from->addDays($this->every),
            'week' => $from->addWeeks($this->every),
            'month' => $from->addMonthsNoOverflow($this->every),
            'year' => $from->addYearsNoOverflow($this->every),
        };
    }

    /** Lectura del medidor en la que toca: la del último servicio (o la primera lectura) + el intervalo. */
    public function nextMeter(): ?float
    {
        if (! $this->every_meter || ! $this->asset->meter_unit) {
            return null;
        }
        $base = $this->lastLog()?->meter_reading ?? $this->asset->meterReadings->first()?->reading ?? 0;

        return $base + $this->every_meter;
    }

    /** overdue | soon | ok | none (sin intervalo) */
    public function status(): string
    {
        $due = $this->nextDueOn();
        $meterDue = $this->nextMeter();
        $meter = $this->asset->currentMeter();
        if (! $due && $meterDue === null) {
            return 'none';
        }

        $today = CarbonImmutable::today();
        if (($due && $due->lt($today)) || ($meterDue !== null && $meter !== null && $meter >= $meterDue)) {
            return 'overdue';
        }
        if (($due && $due->lte($today->addDays(self::SOON_DAYS)))
            || ($meterDue !== null && $meter !== null && $meterDue - $meter <= $this->every_meter * self::SOON_METER)) {
            return 'soon';
        }

        return 'ok';
    }

    /** "15 dic. 2026 o a los 50,000 km". */
    public function dueLabel(): string
    {
        $parts = [];
        if ($due = $this->nextDueOn()) {
            $parts[] = $due->translatedFormat('d M Y');
        }
        if (($meter = $this->nextMeter()) !== null) {
            $parts[] = 'a los '.number_format($meter).' '.$this->asset->meter_unit;
        }

        return implode(' o ', $parts);
    }

    /**
     * Deja una tarea pendiente con el próximo servicio en la lista "Mantenimiento":
     * actualiza la que tiene o, si ya se completó (o no hay), crea otra.
     */
    public function syncTask(): void
    {
        $this->loadMissing('asset');
        if (! $this->asset->isActive()) {
            $this->task()->pending()->delete();

            return;
        }

        $task = $this->task_id ? Task::find($this->task_id) : null;
        if (! $task || $task->isDone()) {
            $task = new Task(['priority' => 2]);
        }
        $this->unsetRelation('logs');
        $meter = $this->nextMeter();
        $task->fill([
            'task_list_id' => TaskList::firstOrCreate(['name' => self::TASK_LIST])->id,
            'title' => "{$this->name} · {$this->asset->name}",
            'due_on' => $this->nextDueOn(),
            'notes' => $meter !== null ? 'Toca a los '.number_format($meter).' '.$this->asset->meter_unit.' (lo que pase primero).' : null,
            'repeat_every' => null,
            'repeat_unit' => null,
        ]);
        $task->taskable()->associate($this->asset);
        $task->save();

        if ($this->task_id !== $task->id) {
            $this->task_id = $task->id;
            $this->saveQuietly();
        }
    }

    /** Al completar su tarea se registra el servicio con esa fecha (sin costo) y se crea el siguiente recordatorio. */
    public function recordFromTask(Task $task): void
    {
        $this->logs()->create([
            'asset_id' => $this->asset_id,
            'done_on' => $task->completed_at->toDateString(),
            'meter_reading' => $this->asset->currentMeter(),
            'task_id' => $task->id,
            'notes' => 'Registrado al completar la tarea.',
        ]);
    }
}
