<?php

namespace App\Support;

/**
 * Códigos de barras de los artículos (EAN-13, EAN-8, UPC-A, GTIN-14…).
 *
 * Se guardan solo con dígitos. Un UPC-A (12 dígitos) es el mismo artículo que
 * su EAN-13 con un 0 al inicio, así que se guarda como EAN-13 para que el
 * mismo producto coincida lo lea quien lo lea. Un código mexicano (750…) de 12
 * dígitos es un EAN-13 sin su dígito verificador: se completa. resources/js/list-grid.js
 * replica normalize(): si cambias uno, cambia el otro.
 */
final class Barcode
{
    /** Regla de validación de un código ya normalizado. */
    public const RULE = 'regex:/^\d{6,14}$/';

    /** Quita espacios y guiones; UPC-A y GTIN-14 con 0 inicial pasan a EAN-13. */
    public static function normalize(?string $value): ?string
    {
        $code = preg_replace('/[\s\-]+/u', '', (string) $value);
        if ($code === '') {
            return null;
        }

        if (ctype_digit($code)) {
            // Tiendas mexicanas (Walmart, Sam's, Chedraui) guardan el EAN sin su dígito
            // verificador y a veces con ceros delante: "00750102051534" → 7501020515343
            $significant = ltrim($code, '0');
            if (strlen($significant) === 12 && str_starts_with($significant, '750')) {
                return $significant.self::checkDigit($significant);
            }
            if (strlen($code) === 12) {
                $code = '0'.$code;
            } elseif (strlen($code) === 14 && $code[0] === '0') {
                $code = substr($code, 1);
            }
        }

        return $code;
    }

    /** Dígito verificador GS1 de los dígitos dados (EAN-13 sin su último dígito, etc.). */
    public static function checkDigit(string $digits): int
    {
        $sum = 0;
        foreach (str_split(strrev($digits)) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }

    /** ¿Lo escrito (en un buscador, por ejemplo) es un código de barras? Devuelve el código normalizado. */
    public static function fromSearch(?string $value): ?string
    {
        $code = self::normalize($value);

        return $code !== null && preg_match('/^\d{6,14}$/', $code) ? $code : null;
    }

    /** Solo EAN/UPC (8, 13 o 14 dígitos ya normalizados) se pueden buscar en internet. */
    public static function isGtin(string $code): bool
    {
        return ctype_digit($code) && in_array(strlen($code), [8, 13, 14], true);
    }
}
