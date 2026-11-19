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
#[Fillable(['name', 'category', 'amount', 'meter_unit', 'every', 'every_unit', 'next_due_on', 'debt_id', 'active', 'notes'])]
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

    /**
     * Registra el pago del recibo que tocaba (con su monto y, si lleva medidor, la lectura final)
     * y pasa al siguiente periodo. El monto pagado queda como el de referencia: luz y agua
     * cambian cada mes y el siguiente recibo suele parecerse al último.
     */
    public function markPaid(?CarbonInterface $paidOn = null, ?float $amount = null, ?string $notes = null, ?float $reading = null): Payment
    {
        return DB::transaction(function () use ($paidOn, $amount, $notes, $reading) {
            $payment = $this->payments()->create([
                'debt_id' => $this->debt_id,
                'paid_on' => ($paidOn ?? today())->toDateString(),
                'amount' => $amount ?? $this->amount,
                'reading' => $reading,
                'due_on' => $this->next_due_on->toDateString(),
                'notes' => $notes,
            ]);
            $this->update(array_filter([
                'next_due_on' => $this->advance($this->next_due_on),
                'amount' => $amount,
            ], fn ($v) => $v !== null));

            return $payment;
        });
    }

    /** El último pago registrado (el del recibo más reciente). */
    public function lastPayment(): ?Payment
    {
        return $this->relationLoaded('payments')
            ? $this->payments->sortByDesc(fn (Payment $p) => [($p->due_on ?? $p->paid_on)->timestamp, $p->id])->first()
            : $this->payments()->reorder()->orderByDesc('due_on')->orderByDesc('id')->first();
    }

    /** Ya se pagó el recibo anterior y el siguiente aún no vence (ni está por vencer). */
    public function isPaidUp(): bool
    {
        return $this->active && $this->lastPayment() !== null && $this->status() === 'ok';
    }

    /**
     * Historial con consumo y precio por unidad de cada periodo (del más reciente al más viejo).
     *
     * @return list<array{payment: Payment, consumption: ?float, unit_price: ?float}>
     */
    public function history(): array
    {
        $payments = $this->payments->sortBy(fn (Payment $p) => [($p->due_on ?? $p->paid_on)->timestamp, $p->id])->values();
        $rows = [];
        $previous = null;
        foreach ($payments as $payment) {
            $rows[] = ['payment' => $payment, 'consumption' => $payment->consumption($previous ?? new Payment), 'unit_price' => $payment->unitPrice($previous ?? new Payment)];
            if ($payment->reading !== null) {
                $previous = $payment;
            }
        }

        return array_reverse($rows);
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
