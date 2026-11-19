<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Models\Setting;
use App\Services\StoreBranches;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    /**
     * Código postal con el que se piden los precios de tu zona a las tiendas en línea. Con
     * otro código postal, la sucursal de los proveedores que se elige de una lista se vuelve a elegir.
     */
    public function update(Request $request)
    {
        $data = $request->validate(['postal_code' => ['nullable', 'digits:5']], [], ['postal_code' => 'código postal']);
        $code = $data['postal_code'] ?? null;
        $changed = StoreBranches::changePostalCode($code);

        return back()->with('success', match (true) {
            ! $code => 'Código postal borrado.',
            $changed => "Código postal {$code} guardado. Revisa la sucursal de cada proveedor.",
            default => 'Guardado.',
        });
    }

    /** Sucursales de una cadena cerca de un código postal (formulario del proveedor y ventana de sucursal). */
    public function branches(Request $request, StoreBranches $branches)
    {
        $data = $request->validate([
            'chain' => ['required', Rule::in(array_keys(StoreBranches::CHAINS))],
            'postal_code' => ['required', 'digits:5'],
            'provider_id' => ['nullable', 'integer'],
        ], [], ['postal_code' => 'código postal']);

        $list = $branches->branches($data['chain'], $data['postal_code']);
        $provider = isset($data['provider_id']) ? Provider::find($data['provider_id']) : null;

        return response()->json([
            'branches' => collect($list)->map(fn (string $label, string $id) => ['id' => $id, 'label' => $label])->values(),
            'listable' => StoreBranches::CHAINS[$data['chain']]['list'],
            'selected' => $provider && $data['postal_code'] === Setting::value(Setting::POSTAL_CODE) ? $provider->branch : null,
        ]);
    }

    /** Guarda el código postal y la sucursal de un proveedor ("auto" = la más cercana / la que asigne la tienda). */
    public function saveBranch(Request $request)
    {
        $data = $request->validate([
            'provider_id' => ['required', 'exists:providers,id'],
            'chain' => ['nullable', Rule::in(array_keys(StoreBranches::CHAINS))],
            'postal_code' => ['required', 'digits:5'],
            'branch' => ['nullable', 'string', 'max:60'],
            'branch_name' => ['nullable', 'string', 'max:150'],
        ], [], ['postal_code' => 'código postal', 'branch' => 'sucursal']);

        $provider = Provider::findOrFail($data['provider_id']);
        $chain = $data['chain'] ?? $provider->chain;
        if (! $chain || ! StoreBranches::isValid($chain, $data['branch'] ?? null)) {
            return response()->json(['message' => 'Esa sucursal no es válida.', 'errors' => ['branch' => ['Esa sucursal no es válida.']]], 422);
        }
        StoreBranches::save($provider, $chain, $data['postal_code'], $data['branch'] ?? null, $data['branch_name'] ?? null);

        return response()->json(['ok' => true, 'label' => $provider->fresh()->branchLabel()]);
    }
}
