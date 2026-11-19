{{--
    Fotos y documentos dentro del formulario de alta y edición (Alpine attachmentsField,
    resources/js/attachments-field.js; lo guarda App\Support\AttachmentForm). El <form>
    lleva enctype="multipart/form-data".
    Uso: <x-attachments-field :owner="$recipe" hint="La primera foto es la portada." />
--}}
@props(['owner' => null, 'title' => 'Fotos y documentos', 'hint' => null])
@php
    $config = [
        'existing' => \App\Support\AttachmentForm::existing($owner),
        'maxFiles' => 10,
        'maxKb' => \App\Models\Attachment::MAX_KB,
    ];
@endphp
<section id="fotos" class="scroll-mt-24 space-y-3" x-data="attachmentsField({{ Js::from($config) }})">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <h2 class="card-title">{{ $title }}</h2>
        <p class="text-xs text-slate-500">{{ $hint ?? 'La primera foto es la portada.' }} Las fotos se reducen antes de subirlas.</p>
    </div>

    {{-- Fotos: la primera es la portada --}}
    <ul x-show="photos.length" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <template x-for="(item, i) in photos" :key="item.key">
            <li class="overflow-hidden rounded-xl bg-slate-50 ring-1" :class="i === 0 ? 'ring-2 ring-emerald-500' : 'ring-slate-200'">
                <div class="relative aspect-square bg-slate-100">
                    <img x-show="item.viewable" :src="item.thumb" alt="" class="size-full object-cover">
                    <span x-show="!item.viewable" class="grid size-full place-items-center p-2 text-center text-xs break-all text-slate-500" x-text="item.name"></span>
                    <span x-show="i === 0" class="badge badge-cheap absolute top-1.5 left-1.5 shadow-sm">Portada</span>
                    <span x-show="item.isNew" class="badge absolute top-1.5 right-1.5 bg-amber-100 text-amber-800 shadow-sm">Nueva</span>
                </div>
                <div class="flex items-center justify-between gap-1 p-1.5">
                    <button type="button" class="btn btn-ghost btn-sm px-2" x-show="i > 0" @click="makeCover(item)" title="Usar como portada">Portada</button>
                    <span x-show="i === 0" class="px-2 text-xs text-emerald-700">★ Portada</span>
                    <div class="flex items-center">
                        <button type="button" class="icon-btn size-8" @click="move(item, -1)" :disabled="i === 0" aria-label="Mover antes" title="Mover antes"><x-icon name="chevron-left" class="size-4" /></button>
                        <button type="button" class="icon-btn size-8" @click="move(item, 1)" :disabled="i === photos.length - 1" aria-label="Mover después" title="Mover después"><x-icon name="chevron-right" class="size-4" /></button>
                        <button type="button" class="icon-btn size-8 text-red-600" @click="remove(item)" aria-label="Quitar foto" title="Quitar"><x-icon name="trash" class="size-4" /></button>
                    </div>
                </div>
            </li>
        </template>
    </ul>

    {{-- Documentos: con título ("Factura", "Póliza") --}}
    <ul x-show="documents.length" class="divide-y divide-slate-100 rounded-xl ring-1 ring-slate-200">
        <template x-for="(item, i) in documents" :key="item.key">
            <li class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                <span class="grid size-8 shrink-0 place-items-center rounded bg-slate-100 text-[10px] font-semibold text-slate-500 uppercase" x-text="item.name.split('.').pop()"></span>
                <div class="min-w-0 flex-1">
                    <input type="text" class="input py-1.5" x-model="item.title" :placeholder="item.name" maxlength="150" :aria-label="'Título de ' + item.name" :disabled="item.isNew">
                    <div class="mt-0.5 truncate text-xs text-slate-500"><span x-text="item.name"></span> · <span x-text="item.size"></span><span x-show="item.isNew" class="text-amber-700"> · se sube al guardar</span></div>
                </div>
                <div class="flex items-center">
                    <button type="button" class="icon-btn size-8" @click="move(item, -1)" :disabled="i === 0" aria-label="Subir">↑</button>
                    <button type="button" class="icon-btn size-8 text-red-600" @click="remove(item)" aria-label="Quitar documento"><x-icon name="trash" class="size-4" /></button>
                </div>
            </li>
        </template>
    </ul>

    <label class="flex cursor-pointer flex-col items-center gap-1 rounded-xl border-2 border-dashed px-4 py-5 text-center text-sm transition"
           :class="dragging ? 'border-emerald-500 bg-emerald-50' : 'border-slate-300 hover:border-emerald-400 hover:bg-slate-50'"
           @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="dragging = false; add([...$event.dataTransfer.files])">
        <input type="file" multiple class="sr-only" @change="pick($event)" accept="image/*,.heic,.heif,.pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.txt,.csv">
        <x-icon name="image" class="size-6 text-slate-400" />
        <span class="font-medium text-slate-700">Agregar fotos o documentos</span>
        <span class="text-xs text-slate-500">Toca para elegir o arrástralos aquí · hasta 10 a la vez, {{ \App\Models\Attachment::MAX_KB / 1024 }} MB cada uno</span>
    </label>
    <p x-show="error" class="text-sm text-red-600" x-text="error" role="alert"></p>
    <p x-show="removed.length" class="text-xs text-slate-500">
        Se quitarán al guardar: <span x-text="removed.map((r) => r.name).join(', ')"></span>.
        <button type="button" class="link" @click="restore(removed.at(-1))">Deshacer</button>
    </p>
    <p x-show="preparing" class="text-sm text-slate-600">Preparando las fotos…</p>
</section>
