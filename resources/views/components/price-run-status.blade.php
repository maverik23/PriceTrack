{{--
    Avance de "Actualizar todos los precios" (corre en el servidor, App\Jobs\ProcessPriceRun).
    Vive en el layout: se ve en cualquier pantalla y sigue al recargar. Alpine priceRunStatus
    (resources/js/online-prices.js) pregunta el avance mientras corre.
--}}
@php $activeRun = \App\Models\PriceRun::active(); @endphp
<div x-data="priceRunStatus({{ Js::from(['run' => $activeRun?->state()]) }})"
     class="fixed right-3 bottom-24 left-3 z-40 sm:left-auto sm:w-96 lg:right-6 lg:bottom-6">
    <x-progress-panel run="run" class="shadow-xl ring-1 ring-slate-200" />
    <div x-show="run.active && run.stalled" x-cloak class="mt-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 shadow-xl ring-1 ring-amber-200">
        Parece detenida: el proceso en segundo plano no avanza.
        <button type="button" class="link" @click="resume()">Reanudar</button>
    </div>
    <div x-show="run.active && !run.stalled" x-cloak class="mt-1 text-right text-xs text-slate-500">Sigue en segundo plano aunque cierres o recargues la página.</div>
    <div x-show="run.finished && run.ok" x-cloak class="mt-2 text-right"><button type="button" class="btn btn-secondary btn-sm shadow" @click="reload()">Ver los precios nuevos</button></div>
    <p x-show="error" x-cloak class="mt-1 text-right text-xs text-red-600" x-text="error"></p>
</div>
