<?php

namespace App\Models;

use App\Models\Concerns\HasSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Un ingreso que se repite (sueldo quincenal, renta mensual, vales de despensa cada mes).
 * Al marcarlo recibido se registra un Income y la próxima fecha avanza un periodo desde la que
 * tocaba (día fijo, como los pagos recurrentes).
 */
#[Fillable(['name', 'category', 'kind', 'amount', 'every', 'every_unit', 'next_on', 'active', 'notes'])]
class IncomeSource extends Model
{
    use HasSchedule;

    /** Días antes de la fecha en que se marca "pronto". */
    public const SOON_DAYS = 5;

    protected $attributes = ['every' => 1, 'every_unit' => 'month', 'active' => true, 'kind' => 'money'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'every' => 'integer',
            'next_on' => 'date',
            'active' => 'boolean',
        ];
    }

    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class)->orderByDesc('received_on')->orderByDesc('id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    public function kindLabel(): string
    {
        return Income::KINDS[$this->kind] ?? 'Dinero';
    }

    /** overdue (ya tocaba y no se ha marcado) | soon | ok | inactive */
    public function status(): string
    {
        $today = CarbonImmutable::today();

        return match (true) {
            ! $this->active => 'inactive',
            $this->next_on->lt($today) => 'overdue',
            $this->next_on->lte($today->addDays(self::SOON_DAYS)) => 'soon',
            default => 'ok',
        };
    }

    /** Registra lo recibido de la fecha que tocaba y pasa al siguiente periodo. */
    public function markReceived(?CarbonInterface $receivedOn = null, ?float $amount = null, ?string $notes = null): Income
    {
        return DB::transaction(function () use ($receivedOn, $amount, $notes) {
            $income = $this->incomes()->create([
                'received_on' => ($receivedOn ?? today())->toDateString(),
                'due_on' => $this->next_on->toDateString(),
                'name' => $this->name,
                'category' => $this->category,
                'kind' => $this->kind,
                'amount' => $amount ?? $this->amount,
                'notes' => $notes,
            ]);
            $this->update(['next_on' => $this->advance($this->next_on)]);

            return $income;
        });
    }

    /** Deshace un ingreso: si era el último, la próxima fecha regresa a la que tocaba. */
    public function undo(Income $income): void
    {
        DB::transaction(function () use ($income) {
            $isLast = $income->due_on && $this->advance($income->due_on)->isSameDay($this->next_on);
            $income->delete();
            if ($isLast) {
                $this->update(['next_on' => $income->due_on]);
            }
        });
    }
}
