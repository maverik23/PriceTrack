{{-- Paginación con el estilo de la app (reemplaza la de Laravel) y cuántos por página (App\Support\Paginate) --}}
@php
    $total = method_exists($paginator, 'total') ? $paginator->total() : null;
    $perPageOptions = \App\Support\Paginate::OPTIONS;
    $showPerPage = $total !== null && $total > min($perPageOptions);
@endphp
@if ($paginator->hasPages() || $showPerPage)
    <nav role="navigation" aria-label="Paginación" class="flex flex-wrap items-center justify-between gap-3 text-sm">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <p class="text-slate-500">
                @if ($paginator->firstItem())
                    {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ number_format($total ?? $paginator->count()) }}
                @else
                    {{ $paginator->count() }} de {{ number_format($total ?? $paginator->count()) }}
                @endif
            </p>
            @if ($showPerPage)
                <div class="flex items-center gap-1" role="group" aria-label="Renglones por página">
                    <span class="text-xs text-slate-500">Por página</span>
                    @foreach ($perPageOptions as $option)
                        @if ($option === $paginator->perPage())
                            <span aria-current="true" class="btn btn-sm bg-brand text-on-brand min-w-8">{{ $option }}</span>
                        @else
                            <a href="{{ request()->fullUrlWithQuery(['per_page' => $option, $paginator->getPageName() => null]) }}" class="btn btn-ghost btn-sm min-w-8">{{ $option }}</a>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
        @if ($paginator->hasPages())
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
        @endif
    </nav>
@endif
