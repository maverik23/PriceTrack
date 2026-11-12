@extends('layouts.app')
@section('title', 'Cambios de precio')

@section('content')
<h1 class="text-2xl font-semibold text-slate-900">Cambios de precio</h1>
<p class="mb-4 text-sm text-slate-500">Cada vez que un artículo cambió de precio por unidad en el mismo proveedor, contra su registro anterior.</p>

@include('reports._range')

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
    <div class="card">
        <div class="stat-label">Subidas</div>
        <div class="stat-value text-red-600">{{ $up }}</div>
    </div>
    <div class="card">
        <div class="stat-label">Bajadas</div>
        <div class="stat-value text-emerald-700">{{ $down }}</div>
    </div>
    <div class="card">
        <div class="stat-label">Cambio promedio</div>
        <div class="stat-value">{{ pct($avgChange) }}</div>
    </div>
</div>

<div class="card overflow-x-auto p-0">
    <table class="table">
        <thead>
        <tr><th>Fecha</th><th>Artículo</th><th>Proveedor</th><th class="text-right">Antes</th><th class="text-right">Ahora</th><th class="text-right">Cambio</th><th class="text-right">Días</th></tr>
        </thead>
        <tbody>
        @forelse ($changes as $c)
            <tr>
                <td class="whitespace-nowrap text-slate-600">{{ $c->current->observed_on->translatedFormat('d M Y') }}</td>
                <td><a class="font-medium hover:text-emerald-700" href="{{ route('products.show', $c->product) }}">{{ $c->product->name }}</a></td>
                <td>{{ $c->provider->name }}</td>
                <td class="text-right tabular-nums text-slate-500">
                    {{ money($c->previous->price) }} <span class="text-xs">({{ $c->previous->presentationLabel() }})</span>
                    <div class="text-xs">{{ money($c->previous->comparePrice()) }}{{ $c->previous->compareLabel() }}</div>
                </td>
                <td class="text-right tabular-nums">
                    {{ money($c->current->price) }} <span class="text-xs text-slate-500">({{ $c->current->presentationLabel() }})</span>
                    <div class="text-xs font-medium">{{ money($c->current->comparePrice()) }}{{ $c->current->compareLabel() }}</div>
                </td>
                <td class="text-right"><span class="badge {{ $c->change > 0 ? 'badge-up' : 'badge-down' }}">{{ $c->change > 0 ? '▲' : '▼' }} {{ pct($c->change) }}</span></td>
                <td class="text-right tabular-nums text-slate-500">{{ $c->days }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-10 text-center text-slate-500">Sin cambios de precio en este periodo.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
