<?php

namespace App\Models\Concerns;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Algo que se repite con día fijo (`every` + `every_unit` y `amount`): pagos recurrentes e
 * ingresos recurrentes. Además de día / semana / mes / año entiende la quincena de los sueldos
 * (`fortnight`: el 15 y el último día del mes).
 */
trait HasSchedule
{
    /** Unidades de repetición: las de las tareas más la quincena. */
    public static function scheduleUnits(bool $withFortnight = false): array
    {
        $units = Task::REPEAT_UNITS;
        if ($withFortnight) {
            $units = ['day' => $units['day'], 'week' => $units['week'], 'fortnight' => ['quincena', 'quincenas'], 'month' => $units['month'], 'year' => $units['year']];
        }

        return $units;
    }

    /** "Cada mes", "Cada 2 meses", "Cada quincena". */
    public function intervalLabel(): string
    {
        [$one, $many] = self::scheduleUnits(true)[$this->every_unit] ?? ['mes', 'meses'];

        return $this->every === 1 ? "Cada {$one}" : "Cada {$this->every} {$many}";
    }

    /** Lo que equivale al mes (uno anual de 1,200 = 100 al mes; uno quincenal de 5,000 = 10,000). */
    public function monthlyAmount(): float
    {
        $months = match ($this->every_unit) {
            'day' => $this->every / 30.436875,
            'week' => $this->every * 7 / 30.436875,
            'fortnight' => $this->every / 2,
            'year' => $this->every * 12,
            default => $this->every,
        };

        return round($this->amount / max($months, 0.0001), 2);
    }

    /** La fecha `$periods` periodos después (meses y años sin desbordar; quincenas en el 15 y el último día). */
    public function advance(CarbonInterface $from, int $periods = 1): CarbonImmutable
    {
        $from = CarbonImmutable::parse($from->toDateString());
        $n = $this->every * $periods;

        if ($this->every_unit === 'fortnight') {
            for ($i = 0; $i < $n; $i++) {
                $from = $from->day < 15 ? $from->setDay(15)
                    : ($from->isLastOfMonth() ? $from->addDay()->setDay(15) : $from->endOfMonth()->startOfDay());
            }

            return $from;
        }

        return match ($this->every_unit) {
            'day' => $from->addDays($n),
            'week' => $from->addWeeks($n),
            'year' => $from->addYearsNoOverflow($n),
            default => $from->addMonthsNoOverflow($n),
        };
    }
}
