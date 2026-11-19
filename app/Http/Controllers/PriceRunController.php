<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPriceRun;
use App\Models\PriceRun;
use App\Models\ProductLink;
use Illuminate\Http\Request;

/**
 * "Actualizar todos los precios" en segundo plano: se inicia aquí, lo corre la cola
 * (App\Jobs\ProcessPriceRun) y cualquier pantalla consulta su avance.
 */
class PriceRunController extends Controller
{
    /** Inicia (o devuelve la que ya corre). `skip_providers[]`: proveedores sin sucursal elegida. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'skip_providers' => ['nullable', 'array'],
            'skip_providers.*' => ['integer'],
        ]);

        if ($active = PriceRun::active()) {
            return response()->json(['run' => $active->state()]);
        }

        $links = ProductLink::with(['product', 'provider'])->whereNotNull('url')
            ->whereNotIn('provider_id', $data['skip_providers'] ?? [])
            ->get()
            ->sortBy(fn (ProductLink $l) => [$l->product?->name, $l->provider?->name]);

        if ($links->isEmpty()) {
            return response()->json(['message' => 'Ningún artículo tiene dirección en alguna tienda. Pégala en la ficha del artículo («En cada tienda») o en una celda de la hoja.'], 422);
        }

        $run = PriceRun::create([
            'title' => 'Actualizando todos los precios',
            'link_ids' => $links->pluck('id')->values()->all(),
        ]);
        ProcessPriceRun::dispatch($run->id);

        return response()->json(['run' => $run->fresh()->state()], 201);
    }

    public function show(PriceRun $run)
    {
        return response()->json(['run' => $run->state()]);
    }

    /** Se detiene después de la tienda en curso. */
    public function cancel(PriceRun $run)
    {
        if ($run->isRunning()) {
            $run->forceFill(['cancel_requested' => true])->save();
            // Si la cola no la está atendiendo, se cierra aquí mismo
            if ($run->isStalled()) {
                $run->finish(PriceRun::CANCELLED);
            }
        }

        return response()->json(['run' => $run->fresh()->state()]);
    }

    /** Sigue una que se detuvo (se cayó o reinició el proceso de la cola). */
    public function resume(PriceRun $run)
    {
        if ($run->isRunning()) {
            $run->touch();
            ProcessPriceRun::dispatch($run->id);
        }

        return response()->json(['run' => $run->fresh()->state()]);
    }
}
