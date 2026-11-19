{{-- Paginación con el estilo de la app (reemplaza la de Laravel) --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Paginación" class="flex flex-wrap items-center justify-between gap-3 text-sm">
        <p class="text-slate-500">
            @if ($paginator->firstItem())
                {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ number_format($paginator->total()) }}
            @else
                {{ $paginator->count() }} de {{ number_format($paginator->total()) }}
            @endif
        </p>
        <div class="flex flex-wrap items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="btn btn-secondary btn-sm opacity-40" aria-disabled="true">← Anterior</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn btn-secondary btn-sm">← Anterior</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-1 text-slate-400" aria-hidden="true">{{ $element }}</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page" class="btn btn-sm bg-brand text-on-brand min-w-7">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="btn btn-ghost btn-sm min-w-7 max-sm:hidden" aria-label="Página {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn btn-secondary btn-sm">Siguiente →</a>
            @else
                <span class="btn btn-secondary btn-sm opacity-40" aria-disabled="true">Siguiente →</span>
            @endif
        </div>
    </nav>
@endif
