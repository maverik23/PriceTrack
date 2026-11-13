{{--
    Botón que abre el escáner de la cámara y escribe el código en el input con id = "for".
    Al leer un código, ese input recibe `input` y `barcode-scanned` ({ code }).
    Uso: <x-scan-button for="barcode" tabindex="-1" />
--}}
@props(['for'])

<button type="button" x-data @click="$dispatch('scan-barcode', { input: @js($for) })"
        title="Escanear código de barras con la cámara" aria-label="Escanear código de barras"
        {{ $attributes->merge(['class' => 'btn btn-secondary shrink-0 px-2.5']) }}>
    <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
        <path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 8v8M10.5 8v8M14 8v8M17 8v8"/>
    </svg>
    {{ $slot }}
</button>
