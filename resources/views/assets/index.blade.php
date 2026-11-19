@extends('layouts.app')
@section('title', 'Bienes')

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-3xl font-bold text-slate-900">Bienes</h1>
        <p class="text-sm text-slate-500">Lo que tienes en casa, cuánto vale y su mantenimiento.</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('reports.assets') }}" class="btn btn-secondary">Reporte</a>
        <a href="{{ route('assets.create') }}" class="btn btn-primary">+ Nuevo bien</a>
    </div>
</div>

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="card">
        <div class="stat-label">Valor de tus bienes hoy</div>
        <div class="stat-value">{{ money($summary['value']) }}</div>
    </div>
    <div class="card">
        <div class="stat-label">Mantenimiento en {{ today()->year }}</div>
        <div class="stat-value">{{ money($summary['maintenanceYear']) }}</div>
    </div>
    <a href="{{ route('reports.assets') }}" class="card hover:ring-emerald-300">
        <div class="stat-label">Servicios vencidos / pronto</div>
        <div class="stat-value"><span class="{{ $summary['overdue']->isNotEmpty() ? 'text-red-600' : '' }}">{{ $summary['overdue']->count() }}</span> <span class="text-base font-normal text-slate-400">/ {{ $summary['soon']->count() }}</span></div>
    </a>
    <a href="{{ route('reports.assets') }}" class="card hover:ring-emerald-300">
        <div class="stat-label">Garantías por vencer ({{ \App\Services\AssetReport::WARRANTY_DAYS }} días)</div>
        <div class="stat-value">{{ $summary['warranties']->count() }}</div>
        @if ($first = $summary['warranties']->first())
            <div class="mt-1 truncate text-xs text-slate-500">{{ $first->name }}: {{ $first->warranty_until->translatedFormat('d M Y') }}</div>
        @endif
    </a>
</div>

<form method="GET" id="assets-filter" class="mb-4 flex flex-wrap items-center gap-2" data-live-filter data-live-links>
    <div class="relative w-full sm:max-w-xs">
        <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
        <input type="search" name="q" value="{{ request('q') }}" autocomplete="off" class="input pl-9" placeholder="Buscar por nombre, marca, modelo, lugar…" aria-label="Buscar bien">
    </div>
    <div id="assets-status" data-live-part class="flex flex-wrap gap-1 text-sm">
        @foreach (\App\Models\Asset::STATUSES + ['all' => 'Todos'] as $value => $label)
            <a href="{{ route('assets.index', array_filter(['status' => $value, 'category' => request('category'), 'q' => request('q')])) }}"
               class="rounded-md px-3 py-1.5 {{ $status === $value ? 'bg-emerald-50 font-semibold text-emerald-700' : 'text-slate-600 hover:bg-slate-100' }}">{{ $label }}</a>
        @endforeach
    </div>
    <input type="hidden" name="status" value="{{ $status }}">
    @if ($categories->isNotEmpty())
        <select name="category" class="input max-w-56" aria-label="Categoría">
            <option value="">Todas las categorías</option>
            @foreach ($categories as $category)
                <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
            @endforeach
        </select>
    @endif
    <span id="assets-clear" data-live-part>
        @if (request()->anyFilled(['q', 'category']))
            <a href="{{ route('assets.index', ['status' => $status]) }}" class="btn btn-ghost">Limpiar</a>
        @endif
    </span>
</form>
<div id="assets-results" data-live-results>

<div class="card overflow-x-auto p-0">
    <table class="table">
        <thead>
        <tr><th>Bien</th><th class="text-right">Valor hoy</th><th class="text-right">Contra la compra</th><th>Próximo servicio</th><th>Garantía</th></tr>
        </thead>
        <tbody>
        @forelse ($assets as $asset)
            @php
                $gain = $asset->gain();
                $plan = $asset->maintenancePlans->sortBy(fn ($p) => [['overdue' => 0, 'soon' => 1, 'ok' => 2, 'none' => 3][$p->status()], $p->nextDueOn()?->toDateString() ?? '9999'])->first();
            @endphp
            <tr>
                <td>
                    <div class="flex items-center gap-3">
                        @if ($cover = $asset->coverPhoto())
                            <img src="{{ $cover->url(true) }}" alt="" loading="lazy" class="size-10 shrink-0 rounded-md object-cover ring-1 ring-slate-200">
                        @endif
                        <div class="min-w-0">
                            <a href="{{ route('assets.show', $asset) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $asset->name }}</a>
                            <div class="text-xs text-slate-500">{{ collect([$asset->category, $asset->location, $asset->isActive() ? null : $asset->statusLabel()])->filter()->join(' · ') }}</div>
                        </div>
                    </div>
                </td>
                <td class="text-right whitespace-nowrap tabular-nums">{{ $asset->isActive() ? money($asset->currentValue()) : '—' }}</td>
                <td class="text-right whitespace-nowrap tabular-nums">
                    @if ($gain !== null)
                        <span class="{{ $gain >= 0 ? 'text-emerald-700' : 'text-red-600' }}">{{ $gain >= 0 ? '+' : '' }}{{ money($gain) }}</span>
                        @if ($asset->purchase_price > 0)<div class="text-xs text-slate-500">{{ pct($gain / $asset->purchase_price) }}</div>@endif
                    @else
                        <span class="text-slate-400">—</span>
                    @endif
                </td>
                <td class="text-sm">
                    @if ($plan && $asset->isActive())
                        @include('assets._plan-status')
                        <div class="text-xs text-slate-600">{{ $plan->name }}@if ($plan->dueLabel()) · {{ $plan->dueLabel() }}@endif</div>
                    @else
                        <span class="text-slate-400">—</span>
                    @endif
                </td>
                <td class="text-sm whitespace-nowrap">
                    @if ($asset->warranty_until)
                        <span class="{{ $asset->warrantyExpiresWithin(\App\Services\AssetReport::WARRANTY_DAYS) ? 'font-semibold text-amber-700' : ($asset->warrantyActive() ? 'text-slate-700' : 'text-slate-400') }}">
                            {{ $asset->warrantyActive() ? 'Hasta' : 'Venció' }} {{ $asset->warranty_until->translatedFormat('d M Y') }}
                        </span>
                    @else
                        <span class="text-slate-400">—</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-10 text-center text-slate-500">
                @if (request()->anyFilled(['q', 'category']))
                    Ningún bien coincide con la búsqueda.
                @else
                No hay bienes aquí. <a class="link" href="{{ route('assets.create') }}">Agrega uno</a>: el auto, la casa, el refrigerador…
                @endif
            </td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $assets->links() }}</div>
</div>
@endsection
