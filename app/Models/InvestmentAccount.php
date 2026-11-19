<?php

namespace App\Models;

use App\Support\Xirr;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Cuenta de inversión con movimientos y valores capturados a mano. Todo se
 * reporta en pesos con el tipo de cambio que se capturó en cada registro.
 */
#[Fillable(['name', 'institution', 'type', 'currency', 'notes', 'closed_on'])]
class InvestmentAccount extends Model
{
    public const TYPES = [
        'cetes' => 'CETES / bonos',
        'fund' => 'Fondo de inversión',
        'stocks' => 'Acciones / ETF',
        'crypto' => 'Cripto',
        'savings' => 'Ahorro',
        'retirement' => 'Retiro (Afore, PPR)',
        'other' => 'Otro',
    ];

    /** Días sin capturar valor para marcarlo como desactualizado. */
    public const STALE_DAYS = 45;

    protected $attributes = ['type' => 'other', 'currency' => 'MXN'];

    protected function casts(): array
    {
        return ['closed_on' => 'date'];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InvestmentTransaction::class)->orderBy('occurred_on')->orderBy('id');
    }

    public function valuations(): HasMany
    {
        return $this->hasMany(InvestmentValuation::class)->orderBy('valued_on')->orderBy('id');
    }

    public function isForeign(): bool
    {
        return $this->currency !== 'MXN';
    }

    public function isClosed(?CarbonInterface $on = null): bool
    {
        return $this->closed_on !== null && $this->closed_on->lte($on ?? today());
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** @return Collection<int, string> */
    public static function institutions(): Collection
    {
        return static::query()->whereNotNull('institution')->where('institution', '!=', '')
            ->distinct()->orderBy('institution')->pluck('institution')->values();
    }

    public function lastValuation(?CarbonInterface $on = null): ?InvestmentValuation
    {
        return $this->valuations->filter(fn (InvestmentValuation $v) => ! $on || $v->valued_on->lte($on))->last();
    }

    /** Aportado neto en pesos hasta una fecha: aportaciones − retiros. */
    public function netContributed(?CarbonInterface $on = null): float
    {
        return round($this->transactions
            ->filter(fn (InvestmentTransaction $t) => ! $on || $t->occurred_on->lte($on))
            ->sum(fn (InvestmentTransaction $t) => $t->contribution()), 2);
    }

    /** Dividendos e intereses (o comisiones) en pesos, informativos. */
    public function totalOf(string $kind): float
    {
        return round($this->transactions->where('kind', $kind)->sum(fn (InvestmentTransaction $t) => $t->amountMxn()), 2);
    }

    /**
     * Valor en pesos en una fecha: la última captura hasta ese día con su tipo de
     * cambio; sin capturas aún, lo aportado hasta ese día. 0 si ya se cerró.
     */
    public function valueOn(CarbonInterface $date): float
    {
        if ($this->isClosed($date)) {
            return 0.0;
        }
        $valuation = $this->lastValuation($date);
        if ($valuation) {
            return $valuation->valueMxn();
        }

        return max(0, $this->netContributed($date));
    }

    public function currentValue(): float
    {
        return $this->valueOn(today());
    }

    /** Ganancia en pesos: valor de hoy (o al cerrar) − aportado neto. */
    public function gain(): float
    {
        return round($this->valueOn($this->asOf()) - $this->netContributed($this->asOf()), 2);
    }

    /** Ganancia ÷ lo aportado (sin tomar en cuenta cuándo). */
    public function simpleReturn(): ?float
    {
        $contributed = $this->transactions->where('kind', 'deposit')->sum(fn (InvestmentTransaction $t) => $t->amountMxn());

        return $contributed > 0 ? $this->gain() / $contributed : null;
    }

    /** Fecha a la que se mide: la última captura de valor (o el cierre). */
    public function asOf(): CarbonImmutable
    {
        if ($this->closed_on) {
            // Al cierre vale 0 y lo que se sacó ya está en los retiros
            return CarbonImmutable::parse($this->closed_on->toDateString());
        }
        $last = $this->lastValuation(today());

        return CarbonImmutable::parse(($last?->valued_on ?? today())->toDateString());
    }

    /**
     * Flujos para el rendimiento: aportaciones negativas, retiros positivos y el
     * valor a la fecha de la última captura como entrada final.
     *
     * @return array<int, array{0: CarbonImmutable, 1: float}>
     */
    public function cashFlows(): array
    {
        $asOf = $this->asOf();
        $flows = $this->transactions
            ->filter(fn (InvestmentTransaction $t) => $t->occurred_on->lte($asOf) && $t->contribution() != 0)
            ->map(fn (InvestmentTransaction $t) => [CarbonImmutable::parse($t->occurred_on->toDateString()), -$t->contribution()])
            ->values()->all();
        $flows[] = [$asOf, $this->valueOn($asOf)];

        return $flows;
    }

    /** Rendimiento anual ponderado por dinero (XIRR). */
    public function annualizedReturn(): ?float
    {
        return Xirr::calculate($this->cashFlows());
    }

    public function isStale(): bool
    {
        if ($this->isClosed()) {
            return false;
        }
        $last = $this->lastValuation();

        return ! $last || $last->valued_on->lt(today()->subDays(self::STALE_DAYS));
    }
}
