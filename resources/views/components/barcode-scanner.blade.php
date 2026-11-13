{{-- Ventana del escáner de códigos de barras (una sola, en el layout). Ver resources/js/barcode-scanner.js --}}
<div x-data="barcodeScanner" x-show="open" x-cloak @scan-barcode.window="start($event.detail)"
     @keydown.escape.window="open && close()" @click.self="close()"
     class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/70 p-4 sm:items-center"
     role="dialog" aria-modal="true" aria-labelledby="scanner-title">
    <div class="w-full max-w-md overflow-hidden rounded-xl bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 id="scanner-title" class="card-title">Escanear código de barras</h2>
            <button type="button" class="rounded px-2 text-xl leading-none text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                    @click="close()" aria-label="Cerrar">×</button>
        </div>

        <div x-show="live" class="relative aspect-[4/3] bg-black">
            <video id="scanner-video" class="size-full object-cover" playsinline muted></video>
            <div class="pointer-events-none absolute inset-x-8 top-1/2 h-28 -translate-y-1/2 rounded-lg border-2 border-emerald-400/90 shadow-[0_0_0_999px_rgba(0,0,0,0.25)]"></div>
        </div>

        <div class="space-y-3 p-4 text-sm">
            <p class="text-slate-600" x-show="status" x-text="status"></p>
            <p class="text-red-600" x-show="error" x-text="error"></p>
            <p class="text-xs text-slate-500" x-show="!live">
                La cámara en vivo solo funciona si entras por HTTPS (o en localhost). Con la foto funciona igual.
            </p>
            <label class="btn w-full" :class="live ? 'btn-secondary' : 'btn-primary'">
                <input type="file" accept="image/*" capture="environment" class="sr-only" @change="fromPhoto($event)">
                Tomar foto del código
            </label>
        </div>
    </div>
</div>
