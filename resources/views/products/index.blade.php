@extends('layouts.app')
@section('title', 'Artículos')

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-3xl font-bold text-slate-900">Artículos</h1>
        <p class="text-sm text-slate-500">El más barato se calcula por unidad ($/L, $/kg, $/pza) con el último precio de cada proveedor.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <button type="button" class="btn btn-secondary" x-data @click="$dispatch('bulk-prices')"
                title="Consulta todas las direcciones guardadas de todos los artículos y registra los precios de hoy">
            <x-icon name="refresh-cw" class="size-4" /> Actualizar todos los precios
        </button>
        <a href="{{ route('products.create') }}" class="btn btn-primary">+ Nuevo artículo</a>
    </div>
</div>

<div x-data="bulkPrices({{ Js::from(['plan' => route('products.online-links')]) }})" @bulk-prices.window="start()">
    <x-progress-panel run="run" class="mb-4" />
    <div x-show="run.finished && run.ok" x-cloak class="-mt-2 mb-4 text-right"><button type="button" class="btn btn-secondary btn-sm" @click="reload()">Ver los precios nuevos</button></div>
    <p x-show="empty" x-cloak class="flash flash-success mb-4" x-text="empty"></p>
</div>

<form method="GET" class="mb-4 flex flex-wrap gap-2" x-data @barcode-scanned="$el.requestSubmit()">
    <div class="flex w-full max-w-xs gap-2">
        <input id="q" name="q" value="{{ request('q') }}" class="input" placeholder="Buscar por nombre, marca o código…">
        <x-scan-button for="q" />
    </div>
    <select name="category" class="input max-w-48" onchange="this.form.submit()">
        <option value="">Todas las categorías</option>
        @foreach ($categories as $category)
            <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
        @endforeach
    </select>
    <button class="btn btn-secondary">Buscar</button>
    @if (request()->hasAny(['q', 'category']))
        <a href="{{ route('products.index') }}" class="btn btn-ghost">Limpiar</a>
    @endif
</form>

<div class="card overflow-x-auto p-0">
    <table class="table">
        <thead>
        <tr>
            <th>Artículo</th>
            <th>Presentación</th>
            <th>Categoría</th>
            <th>Más barato</th>
            <th class="text-right">Precio / unidad</th>
            <th class="text-right">Proveedores</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($products as $product)
            @php($latest = $product->latestPrices())
            @php($cheapest = $latest->first())
            <tr>
                <td>
                    <div class="flex items-center gap-3">
                        @php($cover = $product->coverPhoto())
                        @if ($cover)
                            <img src="{{ $cover->url(true) }}" alt="" loading="lazy" class="size-10 shrink-0 rounded-lg bg-white object-contain ring-1 ring-slate-200">
                        @else
                            <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-400"><x-icon name="package" class="size-4" /></span>
                        @endif
                        <div class="min-w-0">
                            <a href="{{ route('products.show', $product) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $product->name }}</a>
                            @if ($product->brand)<div class="text-xs text-slate-500">{{ $product->brand }}</div>@endif
                        </div>
                    </div>
                </td>
                <td class="text-slate-600">{{ $product->sizeLabel() }}</td>
                <td>@if ($product->category)<span class="badge badge-muted">{{ $product->category }}</span>@endif</td>
                <td>
                    @if ($cheapest)
                        <span class="badge badge-cheap">★ {{ $cheapest->provider->name }}</span>
                        <span class="ml-1 text-xs text-slate-500">{{ money($cheapest->price) }} · {{ $cheapest->presentationLabel() }} · {{ $cheapest->observed_on->translatedFormat('d M') }}</span>
                    @else
                        <a href="{{ route('products.show', $product) }}" class="text-xs text-slate-400 hover:text-emerald-700">Sin precios — agregar</a>
                    @endif
                </td>
                <td class="text-right font-semibold tabular-nums">
                    {{ $cheapest ? money($cheapest->comparePrice()).$cheapest->compareLabel() : '—' }}
                </td>
                <td class="text-right tabular-nums text-slate-500">{{ $latest->count() }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-10 text-center text-slate-500">
                @if ($barcode)
                    Ningún artículo tiene el código <span class="font-mono">{{ $barcode }}</span>.
                    <a class="link" href="{{ route('products.create', ['barcode' => $barcode]) }}">Créalo con sus datos de internet</a>.
                @elseif (request()->anyFilled(['q', 'category']))
                    Sin resultados.
                @else
                    No hay artículos todavía. <a class="link" href="{{ route('products.create') }}">Crea el primero</a>.
                @endif
            </td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
