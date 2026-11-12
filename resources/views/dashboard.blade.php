@extends('layouts.app')

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Hola 👋</h1>
        <p class="text-sm text-slate-500">{{ ucfirst(today()->translatedFormat('l d \d\e F Y')) }}</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('lists.create') }}" class="btn btn-primary">+ Nueva lista</a>
        <a href="{{ route('products.create') }}" class="btn btn-secondary">+ Artículo</a>
    </div>
</div>

@if ($counts['products'] === 0)
    <div class="card mb-6 border-l-4 border-emerald-500">
        <h2 class="card-title">Empieza en 3 pasos</h2>
        <ol class="mt-2 list-inside list-decimal space-y-1 text-sm text-slate-600">
            <li>Revisa tus <a class="link" href="{{ route('providers.index') }}">proveedores</a> (Sam's, Costco, Walmart, Amazon…).</li>
            <li>Da de alta tus <a class="link" href="{{ route('products.create') }}">artículos</a> con su presentación (345 ml, 1 L, 680 g, 1 pza).</li>
            <li>Crea una <a class="link" href="{{ route('lists.create') }}">lista de compra</a>, captura precios y marca dónde compraste.</li>
        </ol>
    </div>
@endif

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <a href="{{ route('reports.spending') }}" class="card hover:ring-emerald-300">
        <div class="stat-label">Gasto este mes</div>
        <div class="stat-value">{{ money($thisMonth['total']) }}</div>
        <div class="mt-1 text-xs text-slate-500">
            Mes pasado: {{ money($lastMonth['total']) }} · Promedio mensual: {{ money($monthlyAvg) }}
        </div>
    </a>
    <a href="{{ route('reports.inflation') }}" class="card hover:ring-emerald-300">
        <div class="stat-label">Inflación de tu canasta (12 meses)</div>
        <div class="stat-value {{ ($inflation['overall'] ?? 0) > 0 ? 'text-red-600' : 'text-emerald-700' }}">{{ pct($inflation['overall']) }}</div>
        <div class="mt-1 text-xs text-slate-500">{{ $inflation['up'] }} artículos subieron · {{ $inflation['down'] }} bajaron</div>
    </a>
    <a href="{{ route('products.index') }}" class="card hover:ring-emerald-300">
        <div class="stat-label">Artículos / proveedores</div>
        <div class="stat-value">{{ $counts['products'] }} <span class="text-base font-normal text-slate-400">/ {{ $counts['providers'] }}</span></div>
    </a>
    <a href="{{ route('prices.index') }}" class="card hover:ring-emerald-300">
        <div class="stat-label">Precios registrados</div>
        <div class="stat-value">{{ number_format($counts['prices']) }}</div>
        <div class="mt-1 text-xs text-slate-500">en {{ $counts['lists'] }} listas</div>
    </a>
</div>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    <div class="card overflow-x-auto p-0">
        <div class="flex items-center justify-between px-5 pt-5 pb-2">
            <h2 class="card-title">Últimas listas</h2>
            <a href="{{ route('lists.index') }}" class="link text-xs">Ver todas</a>
        </div>
        <table class="table">
            <tbody>
            @forelse ($lists as $list)
                <tr>
                    <td class="whitespace-nowrap text-slate-500">{{ $list->shopped_on->translatedFormat('d M') }}</td>
                    <td><a class="font-medium hover:text-emerald-700" href="{{ route('lists.show', $list) }}">{{ $list->name }}</a></td>
                    <td class="text-right text-xs text-slate-500">{{ $list->purchasedCount() }}/{{ $list->items->count() }}</td>
                    <td class="text-right font-semibold tabular-nums">{{ money($list->total()) }}</td>
                </tr>
            @empty
                <tr><td class="py-8 text-center text-slate-500">Sin listas todavía.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="card overflow-x-auto p-0">
        <div class="flex items-center justify-between px-5 pt-5 pb-2">
            <h2 class="card-title">Cambios de precio recientes (60 días)</h2>
            <a href="{{ route('reports.changes') }}" class="link text-xs">Ver todos</a>
        </div>
        <table class="table">
            <tbody>
            @forelse ($changes as $c)
                <tr>
                    <td class="whitespace-nowrap text-slate-500">{{ $c->current->observed_on->translatedFormat('d M') }}</td>
                    <td><a class="font-medium hover:text-emerald-700" href="{{ route('products.show', $c->product) }}">{{ $c->product->name }}</a>
                        <div class="text-xs text-slate-500">{{ $c->provider->name }}</div></td>
                    <td class="text-right tabular-nums text-sm">{{ money($c->current->comparePrice()) }}{{ $c->current->compareLabel() }}</td>
                    <td class="text-right"><span class="badge {{ $c->change > 0 ? 'badge-up' : 'badge-down' }}">{{ pct($c->change) }}</span></td>
                </tr>
            @empty
                <tr><td class="py-8 text-center text-slate-500">Sin cambios recientes.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
