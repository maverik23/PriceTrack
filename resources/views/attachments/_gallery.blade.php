{{--
    Galería de solo lectura: fotos y documentos de un modelo. Se suben, ordenan y eligen
    la portada al editar ($edit = dirección del formulario).
    Variables: $owner (con `attachments` cargado), $edit, $title (opcional)
--}}
@php
    $photos = $owner->attachments->where('kind', 'photo')->values();
    $documents = $owner->attachments->where('kind', 'document');
@endphp
<section class="card space-y-4">
    <div class="flex items-center justify-between gap-2">
        <h2 class="card-title">{{ $title ?? 'Fotos y documentos' }}</h2>
        <a href="{{ $edit }}" class="btn btn-ghost btn-sm">Editar</a>
    </div>

    @if ($photos->isNotEmpty())
        <ul class="grid grid-cols-3 gap-2 sm:grid-cols-4">
            @foreach ($photos as $photo)
                <li class="relative aspect-square overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-200">
                    <a href="{{ $photo->url() }}" target="_blank" rel="noopener" title="{{ $photo->label() }}">
                        @if ($photo->isViewable())
                            <img src="{{ $photo->url(true) }}" alt="{{ $photo->label() }}" loading="lazy" class="size-full object-cover">
                        @else
                            <span class="grid size-full place-items-center p-2 text-center text-xs text-slate-500">{{ $photo->original_name }}</span>
                        @endif
                    </a>
                    @if ($loop->first)<span class="badge badge-cheap absolute bottom-1 left-1">Portada</span>@endif
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
                    <a href="{{ route('attachments.show', [$document, 'download' => 1]) }}" class="btn btn-ghost btn-sm" title="Descargar" aria-label="Descargar {{ $document->label() }}">↓</a>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($photos->isEmpty() && $documents->isEmpty())
        <p class="text-sm text-slate-500">Sin fotos ni documentos. <a class="link" href="{{ $edit }}">Agrégalos al editar</a>.</p>
    @endif
</section>
