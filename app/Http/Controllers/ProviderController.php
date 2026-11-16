<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Models\Setting;
use App\Services\PriceFetcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProviderController extends Controller
{
    public function index(PriceFetcher $fetcher)
    {
        $providers = Provider::withCount('prices')
            ->withMax('prices', 'observed_on')
            ->orderBy('name')
            ->get();

        $postalCode = Setting::value(Setting::POSTAL_CODE);

        return view('providers.index', [
            'providers' => $providers,
            'postalCode' => $postalCode,
            // Tiendas Chedraui cerca del código postal (de la más cercana a la más lejana)
            'chedrauiStores' => $postalCode ? $fetcher->chedrauiStores($postalCode) : [],
            'chedrauiStore' => Setting::value(Setting::CHEDRAUI_STORE),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:providers,name'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        Provider::create($data);

        return back()->with('success', "Proveedor «{$data['name']}» agregado.");
    }

    public function edit(Provider $provider)
    {
        return view('providers.edit', compact('provider'));
    }

    public function update(Request $request, Provider $provider)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('providers', 'name')->ignore($provider)],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $provider->update($data);

        return redirect()->route('providers.index')->with('success', 'Proveedor actualizado.');
    }

    public function destroy(Provider $provider)
    {
        $provider->delete();

        return redirect()->route('providers.index')->with('success', 'Proveedor eliminado (junto con sus precios).');
    }
}
