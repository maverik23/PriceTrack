<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['task_list_id', 'title', 'notes', 'due_on', 'completed_at', 'priority', 'repeat_every', 'repeat_unit', 'taskable_type', 'taskable_id'])]
class Task extends Model
{
    public const PRIORITIES = [3 => 'Alta', 2 => 'Normal', 1 => 'Baja'];

    /** unidad => [singular, plural] */
    public const REPEAT_UNITS = [
        'day' => ['día', 'días'],
        'week' => ['semana', 'semanas'],
        'month' => ['mes', 'meses'],
        'year' => ['año', 'años'],
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'priority' => 'integer',
            'repeat_every' => 'integer',
        ];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'task_list_id');
    }

    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    public function repeatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'repeated_from_id');
    }

    /** Las que se crearon al completar esta (normalmente una). */
    public function repetitions(): HasMany
    {
        return $this->hasMany(self::class, 'repeated_from_id');
    }

    public function scopePending(Builder $query): void
    {
        $query->whereNull('completed_at');
    }

    public function scopeCompleted(Builder $query): void
    {
        $query->whereNotNull('completed_at');
    }

    /** Pendientes con fecha hasta hoy (vencidas y de hoy). */
    public function scopeDueBy(Builder $query, CarbonInterface $day): void
    {
        $query->whereNull('completed_at')->whereNotNull('due_on')->where('due_on', '<', $day->copy()->addDay()->toDateString());
    }

    /** Lo que lleva al arrastrarla al calendario (resources/js/task-calendar.js). */
    public function dragPayload(): string
    {
        return json_encode([
            'id' => $this->id,
            'url' => route('tasks.move', $this),
            'title' => $this->title,
            'due' => $this->due_on?->toDateString(),
        ], JSON_UNESCAPED_UNICODE);
    }

    public function isDone(): bool
    {
        return $this->completed_at !== null;
    }

    public function isRecurring(): bool
    {
        return $this->repeat_every > 0 && isset(self::REPEAT_UNITS[$this->repeat_unit]);
    }

    /** overdue | today | upcoming | undated | done */
    public function status(?CarbonInterface $today = null): string
    {
        $today ??= today();

        return match (true) {
            $this->isDone() => 'done',
            ! $this->due_on => 'undated',
            $this->due_on->lt($today->copy()->startOfDay()) => 'overdue',
            $this->due_on->isSameDay($today) => 'today',
            default => 'upcoming',
        };
    }

    /** "Cada 2 semanas", "Cada mes"; vacío si no se repite. */
    public function repeatLabel(): string
    {
        if (! $this->isRecurring()) {
            return '';
        }
        [$one, $many] = self::REPEAT_UNITS[$this->repeat_unit];

        return $this->repeat_every === 1 ? "Cada {$one}" : "Cada {$this->repeat_every} {$many}";
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? 'Normal';
    }

    /**
     * Siguiente fecha de una tarea repetitiva: el intervalo se cuenta desde el día
     * en que se completó (31 ene + 1 mes = 28 feb, sin desbordar).
     */
    public function nextDueOn(CarbonInterface $completedOn): ?CarbonImmutable
    {
        if (! $this->isRecurring()) {
            return null;
        }
        $from = CarbonImmutable::parse($completedOn->toDateString());

        return match ($this->repeat_unit) {
            'day' => $from->addDays($this->repeat_every),
            'week' => $from->addWeeks($this->repeat_every),
            'month' => $from->addMonthsNoOverflow($this->repeat_every),
            'year' => $from->addYearsNoOverflow($this->repeat_every),
        };
    }

    /** Marca como hecha; si se repite, crea la siguiente. Devuelve la siguiente (o null). */
    public function complete(?CarbonInterface $at = null): ?self
    {
        $at ??= now();

        return DB::transaction(function () use ($at) {
            $this->update(['completed_at' => $at]);
            // Recordatorio de mantenimiento: queda registrado el servicio y se crea el siguiente
            MaintenancePlan::where('task_id', $this->id)->get()->each->recordFromTask($this);
            if (! $this->isRecurring()) {
                return null;
            }

            $next = $this->replicate(['completed_at', 'repeated_from_id', 'due_on']);
            $next->due_on = $this->nextDueOn($at);
            $next->completed_at = null;
            $next->repeated_from_id = $this->id;
            $next->save();

            return $next;
        });
    }

    /** Deshace "hecha": quita la repetición que se había creado si aún no se completa. */
    public function reopen(): void
    {
        DB::transaction(function () {
            $this->repetitions()->whereNull('completed_at')->delete();
            $this->update(['completed_at' => null]);

            // Si al completarla se registró un servicio, se quita y esta vuelve a ser el recordatorio
            MaintenanceLog::with('plan')->where('task_id', $this->id)->get()->each(function (MaintenanceLog $log) {
                if ($plan = $log->plan) {
                    if ($plan->task_id !== $this->id) {
                        Task::whereKey($plan->task_id)->pending()->delete();
                    }
                    $plan->task_id = $this->id;
                    $plan->saveQuietly();
                }
                $log->delete();
            });
        });
    }
}
