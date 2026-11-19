<?php

namespace App\Models;

use App\Models\Concerns\HasSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Un pago que se repite (luz, internet, una suscripción, la mensualidad de una deuda).
 * Al marcarlo pagado la próxima fecha avanza un periodo desde la que tocaba: los pagos
 * tienen día fijo.
 */
#[Fillable(['name', 'category', 'amount', 'every', 'every_unit', 'next_due_on', 'debt_id', 'active', 'notes'])]
class RecurringPayment extends Model
{
    use HasSchedule;

    /** Días antes de la fecha en que se marca "pronto". */
    public const SOON_DAYS = 7;

    protected $attributes = ['every' => 1, 'every_unit' => 'month', 'active' => true];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'every' => 'integer',
            'next_due_on' => 'date',
            'active' => 'boolean',
        ];
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('paid_on')->orderByDesc('id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** @return Collection<int, string> */
    public static function categories(): Collection
    {
        return static::query()->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->orderBy('category')->pluck('category')->values();
    }

    public static function canonicalCategory(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($value === '') {
            return null;
        }
        $key = static fn (string $v) => Str::lower(Str::ascii($v));

        return static::categories()->first(fn ($c) => $key($c) === $key($value)) ?? $value;
    }

    /** overdue | soon | ok | inactive */
    public function status(): string
    {
        $today = CarbonImmutable::today();

        return match (true) {
            ! $this->active => 'inactive',
            $this->next_due_on->lt($today) => 'overdue',
            $this->next_due_on->lte($today->addDays(self::SOON_DAYS)) => 'soon',
            default => 'ok',
        };
    }

    /** Registra el pago de la fecha que tocaba y pasa al siguiente periodo. */
    public function markPaid(?CarbonInterface $paidOn = null, ?float $amount = null, ?string $notes = null): Payment
    {
        return DB::transaction(function () use ($paidOn, $amount, $notes) {
            $payment = $this->payments()->create([
                'debt_id' => $this->debt_id,
                'paid_on' => ($paidOn ?? today())->toDateString(),
                'amount' => $amount ?? $this->amount,
                'due_on' => $this->next_due_on->toDateString(),
                'notes' => $notes,
            ]);
            $this->update(['next_due_on' => $this->advance($this->next_due_on)]);

            return $payment;
        });
    }

    /** Deshace un pago: si era el último, la próxima fecha regresa a la que tocaba. */
    public function undo(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $isLast = $payment->due_on && $this->advance($payment->due_on)->isSameDay($this->next_due_on);
            $payment->delete();
            if ($isLast) {
                $this->update(['next_due_on' => $payment->due_on]);
            }
        });
    }
}
