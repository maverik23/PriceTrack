{{--
    Avance de una operación larga (obtener precios, actualizar todo…): "12 de 48 · Leche — Walmart",
    cuántos salieron bien y cuántos fallaron, botón para cancelar y, al final, el resumen con
    cada fallo y su motivo. Se liga a un objeto de createRun() (resources/js/online-prices.js)
    que vive en el x-data de la pantalla: <x-progress-panel run="run" />.
    Dentro de una tarjeta va con `flat` (una franja, no otra tarjeta). Lo que se ponga dentro
    de la etiqueta sale junto a «Cerrar» al terminar.
--}}
@props(['run' => 'run', 'flat' => false])
<div x-show="{{ $run }}.active || {{ $run }}.finished" x-cloak {{ $attributes->merge(['class' => ($flat ? 'rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200' : 'card').' space-y-3']) }} role="status" aria-live="polite">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="min-w-0">
            <div class="font-semibold text-slate-900" x-text="{{ $run }}.title"></div>
            <div class="text-sm text-slate-600">
                <span x-show="{{ $run }}.active"><span class="tabular-nums" x-text="Math.min({{ $run }}.done + 1, {{ $run }}.total) + ' de ' + {{ $run }}.total"></span> · <span x-text="{{ $run }}.current"></span></span>
                <span x-show="!{{ $run }}.active" x-text="({{ $run }}.cancelled ? 'Cancelado: ' : 'Listo: ') + {{ $run }}.done + ' de ' + {{ $run }}.total"></span>
            </div>
        </div>
        <button type="button" x-show="{{ $run }}.active" class="btn btn-secondary btn-sm" @click="{{ $run }}.cancel()" :disabled="{{ $run }}.cancelled"
                x-text="{{ $run }}.cancelled ? 'Cancelando…' : 'Cancelar'"></button>
        <div x-show="!{{ $run }}.active" class="flex flex-wrap items-center gap-2">
            {{ $slot }}
            <button type="button" class="btn btn-ghost btn-sm" @click="{{ $run }}.dismiss()">Cerrar</button>
        </div>
    </div>
    <div class="h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="Avance" :aria-valuenow="{{ $run }}.percent" aria-valuemin="0" aria-valuemax="100">
        <div class="h-full rounded-full transition-all duration-300" :class="{{ $run }}.failures.length ? 'bg-amber-500' : 'bg-brand'" :style="{ width: {{ $run }}.percent + '%' }"></div>
    </div>
    <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
        <span class="text-emerald-700"><span class="font-semibold tabular-nums" x-text="{{ $run }}.ok"></span> bien</span>
        <span :class="{{ $run }}.failures.length ? 'text-red-600' : 'text-slate-400'"><span class="font-semibold tabular-nums" x-text="{{ $run }}.failures.length"></span> con problema</span>
        <span x-show="{{ $run }}.active" class="text-slate-500"><span class="tabular-nums" x-text="{{ $run }}.total - {{ $run }}.done"></span> por consultar</span>
    </div>
    <details x-show="!{{ $run }}.active && {{ $run }}.failures.length" class="rounded-xl bg-red-50 px-3 py-2 text-sm ring-1 ring-red-200" open>
        <summary class="cursor-pointer font-medium text-red-800">Qué falló y por qué</summary>
        <ul class="mt-2 space-y-1">
            <template x-for="(f, i) in {{ $run }}.failures" :key="i">
                <li><span class="font-medium text-slate-900" x-text="f.label"></span>: <span class="text-slate-600" x-text="f.message"></span></li>
            </template>
        </ul>
    </details>
</div>
