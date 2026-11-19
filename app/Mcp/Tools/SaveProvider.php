<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Provider;
use App\Services\StoreBranches;
use App\Support\ProviderData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Crea una tienda (proveedor) o, con `id`, cambia una existente (solo lo que se manda). `chain` dice de qué cadena es (chedraui, costco, walmart, bodega, sams, soriana) para pedir el precio de su sucursal; `branch` es el id de la sucursal ("auto" = la más cercana; en Chedraui y Costco se eligen de la lista que da set-store-branch, en las demás es el número de tienda) y `branch_name` su nombre.')]
class SaveProvider extends Tool
{
    public function handle(Request $request): Response
    {
        $request->validate(['id' => ['nullable', 'integer']]);
        $provider = $request->get('id') ? $this->find(Provider::class, $request->get('id'), 'una tienda') : new Provider;
        $given = collect($request->all())->except('id')->all();
        if (! $provider->exists && ! array_key_exists('chain', $given)) {
            $given['chain'] = StoreBranches::guess($given['name'] ?? null);
        }
        $data = ProviderData::validate($provider->exists ? array_merge(ProviderData::toInput($provider), $given) : $given, $provider->exists ? $provider : null);

        $creating = ! $provider->exists;
        $provider->fill($data)->save();

        return $this->json(['saved' => $creating ? 'created' : 'updated', 'provider' => $provider->only(['id', 'name', 'chain', 'branch']) + ['branch_label' => $provider->branchLabel()]]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Para cambiar una existente (de list-providers). Sin él se crea.'),
            'name' => $schema->string()->description('Nombre de la tienda.'),
            'notes' => $schema->string(),
            'chain' => $schema->string()->enum(array_keys(StoreBranches::CHAINS))->description('Cadena (se adivina por el nombre al crear).'),
            'branch' => $schema->string()->description('Id de la sucursal o "auto".'),
            'branch_name' => $schema->string()->description('Nombre de la sucursal.'),
        ];
    }
}
