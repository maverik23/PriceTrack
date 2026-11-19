<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lo que se debe. El saldo en una fecha sale de la última captura (o del monto
 * original al inicio), con el interés mensual de su tasa, menos los pagos hechos después.
 */
#[Fillable(['name', 'lender', 'type', 'original_amount', 'annual_rate', 'started_on', 'paid_off_on', 'notes'])]
class Debt extends Model
{
    public const TYPES = [
        'mortgage' => 'Hipoteca',
        'car' => 'Crédito del auto',
        'card' => 'Tarjeta de crédito',
        'personal' => 'Préstamo personal',
        'other' => 'Otra',
    ];

    /** Días promedio de un mes, para el interés entre fechas. */
    private const MONTH_DAYS = 30.436875;

    protected $attributes = ['type' => 'other'];

    protected function casts(): array
    {
        return [
            'original_amount' => 'float',
            'annual_rate' => 'float',
            'started_on' => 'date',
            'paid_off_on' => 'date',
        ];
    }

    public function balances(): HasMany
    {
        return $this->hasMany(DebtBalance::class)->orderBy('balance_on')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function recurringPayments(): HasMany
    {
        return $this->hasMany(RecurringPayment::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function isPaidOff(?CarbonInterface $on = null): bool
    {
        return $this->paid_off_on !== null && $this->paid_off_on->lte($on ?? today());
    }

    public function monthlyRate(): float
    {
        return ($this->annual_rate ?? 0) / 100 / 12;
    }

    /** Saldo en una fecha (0 antes de empezar y desde que se liquidó). */
    public function balanceOn(CarbonInterface $date): float
    {
        $date = CarbonImmutable::parse($date->toDateString());
        if ($this->isPaidOff($date) || ($this->started_on && $date->lt($this->started_on))) {
            return 0.0;
        }

        $capture = $this->balances->filter(fn (DebtBalance $b) => $b->balance_on->lte($date))->last();
        [$from, $balance] = $capture
            ? [CarbonImmutable::parse($capture->balance_on->toDateString()), $capture->balance]
            : [$this->started_on ? CarbonImmutable::parse($this->started_on->toDateString()) : null, $this->original_amount];

        // La captura ya incluye los pagos de ese mismo día
        $payments = $this->payments->filter(fn (Payment $p) => (! $from || $p->paid_on->gt($from)) && $p->paid_on->lte($date));
        foreach ($payments as $payment) {
            $on = CarbonImmutable::parse($payment->paid_on->toDateString());
            $balance = $this->accrue($balance, $from, $on) - $payment->amount;
            $from = $on;
        }

        return round(max(0, $this->accrue($balance, $from, $date)), 2);
    }

    private function accrue(float $balance, ?CarbonImmutable $from, CarbonImmutable $to): float
    {
        if (! $from || $balance <= 0 || ! $this->monthlyRate()) {
            return $balance;
        }

        return $balance * (1 + $this->monthlyRate()) ** ($from->diffInDays($to) / self::MONTH_DAYS);
    }

    public function currentBalance(): float
    {
        return $this->balanceOn(today());
    }

    public function totalPaid(): float
    {
        return round($this->payments->sum('amount'), 2);
    }

    /** Lo que se paga al mes por sus pagos recurrentes activos. */
    public function monthlyPayment(): float
    {
        return round($this->recurringPayments->where('active', true)->sum(fn (RecurringPayment $r) => $r->monthlyAmount()), 2);
    }

    /**
     * Meses que faltan con el pago mensual actual: n = −ln(1 − saldo·i / pago) / ln(1 + i).
     * Null si no hay pago o si el pago no alcanza ni para el interés.
     */
    public function monthsLeft(): ?int
    {
        $balance = $this->currentBalance();
        $payment = $this->monthlyPayment();
        $i = $this->monthlyRate();
        if ($balance <= 0) {
            return 0;
        }
        if ($payment <= 0) {
            return null;
        }
        if (! $i) {
            return (int) ceil($balance / $payment);
        }
        if ($payment <= $balance * $i) {
            return null;
        }

        return (int) ceil(-log(1 - $balance * $i / $payment) / log(1 + $i));
    }

    /**
     * Saldo mes a mes (o cada varios meses, máximo ~120 puntos) desde el inicio hasta hoy o
     * la liquidación, para la gráfica. Necesita `balances` y `payments`.
     *
     * @return array<int, array{x: string, y: float}>
     */
    public function balanceSeries(): array
    {
        $start = CarbonImmutable::parse(($this->started_on ?? $this->balances->first()?->balance_on ?? $this->payments->first()?->paid_on ?? today())->toDateString());
        $end = $this->paid_off_on ? CarbonImmutable::parse($this->paid_off_on->toDateString())->min(today()) : CarbonImmutable::today();
        $step = max(1, (int) ceil($start->diffInMonths($end) / 120));

        $series = [];
        for ($d = $start; $d->lt($end); $d = $d->addMonthsNoOverflow($step)) {
            $series[] = ['x' => $d->toDateString(), 'y' => $this->balanceOn($d)];
        }
        $series[] = ['x' => $end->toDateString(), 'y' => $this->balanceOn($end)];

        return $series;
    }
}
