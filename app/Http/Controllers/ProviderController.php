<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Models\Setting;
use App\Support\Paginate;
use App\Support\ProviderData;
use Illuminate\Http\Request;

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
            ->paginate(Paginate::perPage($request))
            ->withQueryString();

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

    private function validated(Request $request, ?Provider $provider = null): array
    {
        return ProviderData::validate($request->all(), $provider);
    }
}
