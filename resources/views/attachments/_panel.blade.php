{{--
    Fotos y documentos de un modelo. $owner (con `attachments` cargado), $action (ruta de subida),
    $title, $hint
--}}
@php
    $photos = $owner->attachments->where('kind', 'photo');
    $documents = $owner->attachments->where('kind', 'document');
@endphp
<section class="card space-y-4">
    <div>
        <h2 class="card-title">{{ $title ?? 'Fotos y documentos' }}</h2>
        @isset($hint)<p class="text-xs text-slate-500">{{ $hint }}</p>@endisset
    </div>

    @if ($photos->isNotEmpty())
        <ul class="grid grid-cols-3 gap-2 sm:grid-cols-4">
            @foreach ($photos as $photo)
                <li class="group relative aspect-square overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-200">
                    <a href="{{ $photo->url() }}" target="_blank" rel="noopener" title="{{ $photo->label() }}">
                        @if ($photo->isViewable())
                            <img src="{{ $photo->url(true) }}" alt="{{ $photo->label() }}" loading="lazy" class="size-full object-cover">
                        @else
                            <span class="grid size-full place-items-center p-2 text-center text-xs text-slate-500">{{ $photo->original_name }}</span>
                        @endif
                    </a>
                    @if ($loop->first)<span class="badge badge-cheap absolute bottom-1 left-1">Portada</span>@endif
                    <form method="POST" action="{{ route('attachments.destroy', $photo) }}" class="absolute top-1 right-1" onsubmit="return confirm('¿Eliminar la foto?')">
                        @csrf @method('DELETE')
                        <button class="grid size-7 place-items-center rounded-full bg-white/90 text-red-600 shadow ring-1 ring-slate-200 hover:bg-white" title="Eliminar foto" aria-label="Eliminar foto {{ $photo->label() }}">✕</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($documents->isNotEmpty())
        <ul class="divide-y divide-slate-100 text-sm">
            @foreach ($documents as $document)
                <li class="flex items-center gap-2 py-2">
                    <span class="grid size-8 shrink-0 place-items-center rounded bg-slate-100 text-[10px] font-semibold text-slate-500 uppercase">{{ \Illuminate\Support\Str::afterLast($document->original_name, '.') }}</span>
                    <div class="min-w-0 flex-1">
                        <a href="{{ $document->isViewable() ? $document->url() : route('attachments.show', [$document, 'download' => 1]) }}" target="_blank" rel="noopener" class="block truncate font-medium text-slate-900 hover:text-emerald-700">{{ $document->label() }}</a>
                        <div class="text-xs text-slate-500">{{ $document->sizeLabel() }} · {{ $document->created_at->translatedFormat('d M Y') }}</div>
                    </div>
                    <a href="{{ route('attachments.show', [$document, 'download' => 1]) }}" class="btn btn-ghost btn-sm" title="Descargar">↓</a>
                    <form method="POST" action="{{ route('attachments.destroy', $document) }}" onsubmit="return confirm('¿Eliminar el documento?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-danger btn-sm" title="Eliminar documento" aria-label="Eliminar {{ $document->label() }}">✕</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="space-y-2" x-data="attachmentUpload" @submit.prevent="submit($event)">
        @csrf
        <label class="btn btn-secondary w-full cursor-pointer">
            <input type="file" name="files[]" data-files multiple class="sr-only" @change="picked($event)"
                   accept="image/*,.heic,.heif,.pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.txt,.csv">
            📎 <span x-text="names || 'Elegir fotos o documentos'">Elegir fotos o documentos</span>
        </label>
        <div x-show="names" x-cloak class="flex gap-2">
            <input name="title" class="input" maxlength="150" placeholder="Título (opcional): Factura, Póliza…" aria-label="Título">
            <button class="btn btn-primary" :disabled="busy"><span x-text="busy ? 'Subiendo…' : 'Subir'">Subir</span></button>
        </div>
        <p class="text-xs text-slate-400">Hasta 10 archivos de {{ \App\Models\Attachment::MAX_KB / 1024 }} MB. Las fotos se reducen antes de subirlas.</p>
    </form>
</section>
