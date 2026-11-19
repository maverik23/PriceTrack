<?php

namespace App\Support;

use App\Models\Provider;
use App\Services\StoreBranches;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Campos de un proveedor (ProviderController y el MCP save-provider): nombre, notas, cadena y
 * sucursal. La sucursal es el id de la tienda ("auto" = la más cercana o la que asigne la tienda);
 * sin cadena no hay sucursal.
 */
class ProviderData
{
    public static function validate(array $input, ?Provider $provider = null): array
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100', Rule::unique('providers', 'name')->ignore($provider)],
            'notes' => ['nullable', 'string', 'max:255'],
            'chain' => ['nullable', Rule::in(array_keys(StoreBranches::CHAINS))],
            'branch' => ['nullable', 'string', 'max:60'],
            'branch_name' => ['nullable', 'string', 'max:150'],
        ], [], ['chain' => 'cadena', 'branch' => 'sucursal', 'branch_name' => 'nombre de la sucursal'])->validate();

        $chain = $data['chain'] ?? null;
        $branch = $chain ? trim((string) ($data['branch'] ?? '')) ?: null : null;
        if ($chain && ! StoreBranches::isValid($chain, $branch)) {
            throw ValidationException::withMessages(['branch' => StoreBranches::CHAINS[$chain]['list']
                ? 'Elige una sucursal de la lista.'
                : 'Escribe el número de la tienda (solo dígitos).']);
        }

        return [
            'name' => $data['name'],
            'notes' => $data['notes'] ?? null,
            'chain' => $chain,
            'branch' => $branch,
            'branch_name' => $branch && $branch !== StoreBranches::AUTO && filled($data['branch_name'] ?? null)
                ? Str::before(trim($data['branch_name']), ' · ')
                : null,
        ];
    }

    public static function toInput(Provider $provider): array
    {
        return $provider->only(['name', 'notes', 'chain', 'branch', 'branch_name']);
    }
}
