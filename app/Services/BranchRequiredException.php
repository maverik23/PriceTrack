<?php

namespace App\Services;

use RuntimeException;

/**
 * La tienda cambia el precio por sucursal y falta el código postal o la sucursal: hay
 * que preguntarlos antes de consultar. `info` dice qué cadena y qué falta.
 */
class BranchRequiredException extends RuntimeException
{
    /** @param  array{chain: string, name: string, postal_code: ?string, missing: string}  $info */
    public function __construct(public readonly array $info)
    {
        parent::__construct($info['missing'] === 'postal_code'
            ? "Para traer el precio de {$info['name']} hace falta tu código postal y tu sucursal."
            : "Elige tu sucursal de {$info['name']}: cada una tiene su precio.");
    }
}
