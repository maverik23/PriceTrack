<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Provider;
use App\Services\StoreBranches;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Código postal y sucursal de un proveedor (cada proveedor tiene la suya; ver `chain` y `branch` en list-providers). En las cadenas que publican sus sucursales (chedraui, costco), sin `branch` devuelve las cercanas a ese código postal (de la más cercana a la más lejana) para que la persona elija; con `branch` (el `id` de una de ellas, o "auto" = la más cercana que tenga el artículo) la guarda. En walmart, bodega, sams y soriana `branch` es el número de la tienda y `branch_name` su nombre. Con otro código postal se vuelve a preguntar la sucursal de los demás proveedores que la eligen de una lista.')]
class SetStoreBranch extends Tool
{
    public function handle(Request $request, StoreBranches $branches): Response
    {
        $data = $request->validate([
            'provider_id' => ['required', 'integer'],
            'postal_code' => ['required', 'digits:5'],
            'branch' => ['nullable', 'string', 'max:60'],
            'branch_name' => ['nullable', 'string', 'max:150'],
        ], [], ['provider_id' => 'proveedor', 'postal_code' => 'código postal', 'branch' => 'sucursal']);

        $provider = $this->find(Provider::class, $data['provider_id'], 'proveedor');
        $chain = $provider->chain;
        if (! $chain) {
            throw ValidationException::withMessages(['provider_id' => "«{$provider->name}» no es de una cadena con sucursales; ponle cadena al editarlo en Proveedores."]);
        }

        if (! filled($data['branch'] ?? null)) {
            $list = $branches->branches($chain, $data['postal_code']);

            return $this->json([
                'provider' => $provider->name,
                'chain' => $chain,
                'postal_code' => $data['postal_code'],
                'listable' => StoreBranches::CHAINS[$chain]['list'],
                'branches' => collect($list)->map(fn (string $label, string $id) => ['id' => $id, 'label' => $label])->values()->all(),
                'current' => $provider->branch,
            ]);
        }

        if (! StoreBranches::isValid($chain, $data['branch'])) {
            throw ValidationException::withMessages(['branch' => 'Esa sucursal no es válida: usa el id de la lista, el número de la tienda o "auto".']);
        }
        $name = $data['branch_name'] ?? ($branches->branches($chain, $data['postal_code'])[$data['branch']] ?? null);
        StoreBranches::save($provider, $chain, $data['postal_code'], $data['branch'], $name);

        return $this->json(['saved' => true, 'provider' => $provider->name, 'postal_code' => $data['postal_code'], 'branch' => $data['branch'], 'label' => $provider->fresh()->branchLabel()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider_id' => $schema->integer()->description('El proveedor (de list-providers).')->required(),
            'postal_code' => $schema->string()->description('Código postal de 5 dígitos.')->required(),
            'branch' => $schema->string()->description('Id de la sucursal (de la lista), número de la tienda o "auto". Sin él, solo lista las sucursales.'),
            'branch_name' => $schema->string()->description('Nombre de la sucursal, si se escribe a mano.'),
        ];
    }
}
