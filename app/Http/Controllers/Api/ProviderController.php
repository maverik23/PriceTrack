<?php

namespace App\Http\Controllers\Api;

use App\Api\Present;
use App\Models\Provider;
use App\Models\Setting;
use App\Services\StoreBranches;
use App\Support\ProviderData;
use Illuminate\Http\Request;

/** Tiendas para la app móvil. Las reglas son las de la web (ProviderData). */
class ProviderController extends Controller
{
    public function index(Request $request)
    {
        $providers = Provider::withCount('prices')
            ->withMax('prices', 'observed_on')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('notes', 'like', $term)->orWhere('branch_name', 'like', $term));
            })
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->page($providers, fn (Provider $p) => Present::provider($p), [
            'postal_code' => Setting::value(Setting::POSTAL_CODE),
            // Cadenas con sucursal: cuáles se eligen de una lista y cuáles se escriben
            'chains' => collect(StoreBranches::CHAINS)->map(fn (array $c, string $id) => ['id' => $id, 'name' => $c['name'], 'list' => $c['list']])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $provider = Provider::create(ProviderData::validate($request->all()));

        return response()->json(['data' => Present::provider($provider), 'message' => "Proveedor «{$provider->name}» agregado."], 201);
    }

    public function update(Request $request, Provider $provider)
    {
        $provider->update(ProviderData::validate($request->all() + ProviderData::toInput($provider), $provider));

        return response()->json(['data' => Present::provider($provider), 'message' => 'Proveedor actualizado.']);
    }

    public function destroy(Provider $provider)
    {
        $provider->delete();

        return response()->json(['message' => 'Proveedor eliminado (junto con sus precios).']);
    }

    /** Código postal con el que se piden los precios de tu zona (vacío lo borra). */
    public function postalCode(Request $request)
    {
        $data = $request->validate(['postal_code' => ['nullable', 'digits:5']], [], ['postal_code' => 'código postal']);
        $code = $data['postal_code'] ?? null;
        $changed = StoreBranches::changePostalCode($code);

        return response()->json([
            'postal_code' => $code,
            'message' => match (true) {
                ! $code => 'Código postal borrado.',
                $changed => "Código postal {$code} guardado. Revisa la sucursal de cada proveedor.",
                default => 'Guardado.',
            },
        ]);
    }
}
