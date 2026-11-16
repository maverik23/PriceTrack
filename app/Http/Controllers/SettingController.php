<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

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
            'chedraui_store' => ['nullable', 'regex:/^chedrauimx\w+$/'],
        ]);
        $code = $data['postal_code'] ?? null;
        $sameZone = $code && $code === Setting::value(Setting::POSTAL_CODE);

        Setting::put(Setting::POSTAL_CODE, $code);
        // Con otro código postal cambian las tiendas cercanas: se vuelve a elegir
        Setting::put(Setting::CHEDRAUI_STORE, $sameZone ? ($data['chedraui_store'] ?? null) : null);

        return back()->with('success', match (true) {
            ! $code => 'Código postal borrado.',
            ! $sameZone => "Código postal {$code} guardado. Si quieres, elige tu tienda Chedraui.",
            default => 'Guardado.',
        });
    }
}
