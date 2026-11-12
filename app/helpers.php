<?php

if (! function_exists('money')) {
    function money(?float $value, int $decimals = 2): string
    {
        if ($value === null) {
            return '—';
        }

        return ($value < 0 ? '-$' : '$').number_format(abs($value), $decimals);
    }
}

if (! function_exists('qty')) {
    /** 345.000 → "345", 1.500 → "1.5" */
    function qty(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return rtrim(rtrim(number_format($value, 3, '.', ','), '0'), '.');
    }
}

if (! function_exists('pct')) {
    /** 0.0523 → "+5.2%" */
    function pct(?float $ratio, int $decimals = 1): string
    {
        if ($ratio === null) {
            return '—';
        }

        return ($ratio > 0 ? '+' : '').number_format($ratio * 100, $decimals).'%';
    }
}

if (! function_exists('num')) {
    /** Número plano para inputs: 345.000 → "345", 1.500 → "1.5", null → "" */
    function num(?float $value): string
    {
        return $value === null ? '' : rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
