<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Setting;
use App\Services\StoreBranches;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Código postal y sucursal de las tiendas que cambian el precio por sucursal (hoy, `chedraui`). Sin `branch` devuelve las sucursales cercanas a ese código postal (de la más cercana a la más lejana) para que la persona elija; con `branch` (el `id` de una de ellas, o "auto" = la más cercana que tenga el artículo) la guarda. Con otro código postal se vuelve a preguntar la sucursal de las demás cadenas.')]
class SetStoreBranch extends Tool
{
    public function handle(Request $request, StoreBranches $branches): Response
    {
        $data = $request->validate([
            'chain' => ['required', Rule::in(array_keys(StoreBranches::CHAINS))],
            'postal_code' => ['required', 'digits:5'],
            'branch' => ['nullable', 'string', 'max:60'],
        ], [], ['chain' => 'cadena', 'postal_code' => 'código postal', 'branch' => 'sucursal']);

        if (! filled($data['branch'] ?? null)) {
            return $this->json([
                'chain' => $data['chain'],
                'postal_code' => $data['postal_code'],
                'branches' => collect($branches->branches($data['chain'], $data['postal_code']))
                    ->map(fn (string $label, string $id) => ['id' => $id, 'label' => $label])->values()->all(),
                'current' => Setting::value(StoreBranches::CHAINS[$data['chain']]['setting']),
            ]);
        }

        if (! StoreBranches::isValid($data['chain'], $data['branch'])) {
            throw ValidationException::withMessages(['branch' => 'Esa sucursal no es válida: usa el id de la lista o "auto".']);
        }
        StoreBranches::save($data['chain'], $data['postal_code'], $data['branch']);

        return $this->json(['saved' => true, 'chain' => $data['chain'], 'postal_code' => $data['postal_code'], 'branch' => $data['branch']]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'chain' => $schema->string()->enum(array_keys(StoreBranches::CHAINS))->description('La cadena.')->required(),
            'postal_code' => $schema->string()->description('Código postal de 5 dígitos.')->required(),
            'branch' => $schema->string()->description('Id de la sucursal (de la lista) o "auto". Sin él, solo lista las sucursales.'),
        ];
    }
}
