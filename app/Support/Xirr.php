<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Tasa interna de retorno con fechas (como XIRR de Excel): la tasa anual r con la
 * que Σ flujo / (1 + r)^(días / 365) = 0. Aportaciones negativas, retiros y valor
 * final positivos.
 */
class Xirr
{
    /**
     * @param  array<int, array{0: CarbonInterface, 1: float}>  $flows  [fecha, monto]
     */
    public static function calculate(array $flows): ?float
    {
        $flows = array_values(array_filter($flows, fn ($f) => abs($f[1]) > 0.000001));
        if (count($flows) < 2) {
            return null;
        }
        $amounts = array_column($flows, 1);
        if (min($amounts) >= 0 || max($amounts) <= 0) {
            return null;
        }

        usort($flows, fn ($a, $b) => $a[0] <=> $b[0]);
        $start = $flows[0][0];
        $points = array_map(fn ($f) => [$start->diffInDays($f[0]) / 365, $f[1]], $flows);
        if (end($points)[0] <= 0) {
            return null;
        }

        $npv = function (float $rate) use ($points): float {
            $sum = 0.0;
            foreach ($points as [$t, $amount]) {
                $sum += $amount / (1 + $rate) ** $t;
            }

            return $sum;
        };

        // Newton desde 10 %; si no converge, bisección entre −99.99 % y +10,000 %
        $rate = 0.1;
        for ($i = 0; $i < 100; $i++) {
            $value = $npv($rate);
            $derivative = 0.0;
            foreach ($points as [$t, $amount]) {
                $derivative -= $t * $amount / (1 + $rate) ** ($t + 1);
            }
            if (abs($derivative) < 1e-12) {
                break;
            }
            $next = $rate - $value / $derivative;
            if (! is_finite($next) || $next <= -0.9999) {
                break;
            }
            if (abs($next - $rate) < 1e-10) {
                return $next;
            }
            $rate = $next;
        }

        [$low, $high] = [-0.9999, 100.0];
        $fLow = $npv($low);
        if ($fLow * $npv($high) > 0) {
            return null;
        }
        for ($i = 0; $i < 300; $i++) {
            $mid = ($low + $high) / 2;
            $fMid = $npv($mid);
            if (abs($fMid) < 1e-7 || ($high - $low) / 2 < 1e-10) {
                return $mid;
            }
            if ($fMid * $fLow < 0) {
                $high = $mid;
            } else {
                [$low, $fLow] = [$mid, $fMid];
            }
        }

        return ($low + $high) / 2;
    }
}
