@extends('layouts.app')
@section('title', 'Artículos')

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Artículos</h1>
        <p class="text-sm text-slate-500">El más barato se calcula por unidad ($/L, $/kg, $/pza) con el último precio de cada proveedor.</p>
    </div>
    <a href="{{ route('products.create') }}" class="btn btn-primary">+ Nuevo artículo</a>
</div>

<form method="GET" class="mb-4 flex flex-wrap gap-2">
    <input name="q" value="{{ request('q') }}" class="input max-w-xs" placeholder="Buscar por nombre o marca…">
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
                    <a href="{{ route('products.show', $product) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $product->name }}</a>
                    @if ($product->brand)<div class="text-xs text-slate-500">{{ $product->brand }}</div>@endif
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
            <tr><td colspan="6" class="py-10 text-center text-slate-500">No hay artículos todavía. <a class="link" href="{{ route('products.create') }}">Crea el primero</a>.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
