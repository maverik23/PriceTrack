<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /** Código postal con el que se piden los precios de tu zona a las tiendas en línea. */
    public function update(Request $request)
    {
        $code = $request->validate(['postal_code' => ['nullable', 'digits:5']])['postal_code'] ?? null;
        Setting::put(Setting::POSTAL_CODE, $code);

        return back()->with('success', $code ? "Código postal {$code} guardado." : 'Código postal borrado.');
    }
}
