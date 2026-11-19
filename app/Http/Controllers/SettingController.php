<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\StoreBranches;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    /**
     * Código postal con el que se piden los precios de tu zona a las tiendas en línea, y
     * tu tienda Chedraui (cada sucursal tiene su precio; vacío = la más cercana que lo tenga).
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'postal_code' => ['nullable', 'digits:5'],
            'chedraui_store' => ['nullable', 'regex:/^(chedrauimx\w+|auto)$/'],
        ]);
        $code = $data['postal_code'] ?? null;
        $sameZone = $code && $code === Setting::value(Setting::POSTAL_CODE);

        Setting::put(Setting::POSTAL_CODE, $code);
        // Con otro código postal cambian las tiendas cercanas: se vuelve a elegir
        Setting::put(Setting::CHEDRAUI_STORE, $sameZone ? ($data['chedraui_store'] ?? StoreBranches::AUTO) : null);

        return back()->with('success', match (true) {
            ! $code => 'Código postal borrado.',
            ! $sameZone => "Código postal {$code} guardado. Ahora elige tu tienda Chedraui.",
            default => 'Guardado.',
        });
    }

    /** Sucursales de una cadena cerca de un código postal (para la ventana de elegir sucursal). */
    public function branches(Request $request, StoreBranches $branches)
    {
        $data = $request->validate([
            'chain' => ['required', Rule::in(array_keys(StoreBranches::CHAINS))],
            'postal_code' => ['required', 'digits:5'],
        ], [], ['postal_code' => 'código postal']);

        $list = $branches->branches($data['chain'], $data['postal_code']);

        return response()->json([
            'branches' => collect($list)->map(fn (string $label, string $id) => ['id' => $id, 'label' => $label])->values(),
            'selected' => $data['postal_code'] === Setting::value(Setting::POSTAL_CODE) ? Setting::value(StoreBranches::CHAINS[$data['chain']]['setting']) : null,
        ]);
    }

    /** Guarda el código postal y la sucursal de una cadena ("auto" = la más cercana que lo tenga). */
    public function saveBranch(Request $request)
    {
        $data = $request->validate([
            'chain' => ['required', Rule::in(array_keys(StoreBranches::CHAINS))],
            'postal_code' => ['required', 'digits:5'],
            'branch' => ['nullable', 'string', 'max:60'],
        ], [], ['postal_code' => 'código postal', 'branch' => 'sucursal']);

        if (! StoreBranches::isValid($data['chain'], $data['branch'] ?? null)) {
            return response()->json(['message' => 'Esa sucursal no es válida.', 'errors' => ['branch' => ['Esa sucursal no es válida.']]], 422);
        }
        StoreBranches::save($data['chain'], $data['postal_code'], $data['branch'] ?? null);

        return response()->json(['ok' => true]);
    }
}
