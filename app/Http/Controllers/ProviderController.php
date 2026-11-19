<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Models\Setting;
use App\Services\StoreBranches;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProviderController extends Controller
{
    public function index()
    {
        $providers = Provider::withCount('prices')
            ->withMax('prices', 'observed_on')
            ->orderBy('name')
            ->get();

        return view('providers.index', [
            'providers' => $providers,
            'postalCode' => Setting::value(Setting::POSTAL_CODE),
        ]);
    }

    public function store(Request $request)
    {
        $provider = Provider::create($this->validated($request));

        return back()->with('success', "Proveedor «{$provider->name}» agregado.");
    }

    public function edit(Provider $provider)
    {
        return view('providers.edit', [
            'provider' => $provider,
            'postalCode' => Setting::value(Setting::POSTAL_CODE),
        ]);
    }

    public function update(Request $request, Provider $provider)
    {
        $provider->update($this->validated($request, $provider));

        return redirect()->route('providers.index')->with('success', 'Proveedor actualizado.');
    }

    public function destroy(Provider $provider)
    {
        $provider->delete();

        return redirect()->route('providers.index')->with('success', 'Proveedor eliminado (junto con sus precios).');
    }

    /**
     * Nombre, notas, cadena y sucursal. La sucursal es el id de la tienda ("auto" = la más
     * cercana o la que asigne la tienda); sin cadena no hay sucursal.
     */
    private function validated(Request $request, ?Provider $provider = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('providers', 'name')->ignore($provider)],
            'notes' => ['nullable', 'string', 'max:255'],
            'chain' => ['nullable', Rule::in(array_keys(StoreBranches::CHAINS))],
            'branch' => ['nullable', 'string', 'max:60'],
            'branch_name' => ['nullable', 'string', 'max:150'],
        ], [], ['chain' => 'cadena', 'branch' => 'sucursal', 'branch_name' => 'nombre de la sucursal']);

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
}
