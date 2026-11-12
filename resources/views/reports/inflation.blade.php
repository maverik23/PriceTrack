@extends('layouts.app')
@section('title', 'Inflación')

@section('content')
<h1 class="text-2xl font-semibold text-slate-900">Inflación de tu canasta</h1>
<p class="mb-4 text-sm text-slate-500">Calculada con tus propios precios por unidad, comparando cada artículo contra sí mismo en el mismo proveedor.</p>

@include('reports._range')

@if ($products->isEmpty())
    <div class="card py-12 text-center text-slate-500">Se necesitan al menos dos precios del mismo artículo y proveedor en el periodo para medir la inflación.</div>
@else
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card">
            <div class="stat-label">Inflación del periodo (índice)</div>
            <div class="stat-value {{ $overall > 0 ? 'text-red-600' : 'text-emerald-700' }}">{{ pct($overall) }}</div>
            <div class="mt-1 text-xs text-slate-500">{{ $observations }} precios registrados</div>
        </div>
        <div class="card">
            <div class="stat-label">Anualizada</div>
            <div class="stat-value">{{ pct($annualized) }}</div>
            <div class="mt-1 text-xs text-slate-500">{{ $annualized === null ? 'se necesitan 3+ meses' : 'ritmo equivalente a 12 meses' }}</div>
        </div>
        <div class="card">
            <div class="stat-label">Promedio por artículo</div>
            <div class="stat-value">{{ pct($products->avg('change')) }}</div>
            <div class="mt-1 text-xs text-slate-500">primer vs. último precio</div>
        </div>
        <div class="card">
            <div class="stat-label">Artículos</div>
            <div class="mt-2 flex gap-2 text-sm">
                <span class="badge badge-up">▲ {{ $up }} subieron</span>
                <span class="badge badge-down">▼ {{ $down }} bajaron</span>
                @if ($flat)<span class="badge badge-muted">= {{ $flat }}</span>@endif
            </div>
        </div>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <h2 class="card-title">Índice de precios (base 100)</h2>
            <p class="mb-3 text-xs text-slate-500">Variación mensual encadenada (media geométrica por artículo + proveedor).</p>
            <div class="h-72">
                <canvas data-chart="{{ json_encode(['type' => 'line', 'format' => 'index', 'labels' => $monthly->pluck('label'), 'datasets' => [['label' => 'Índice', 'data' => $monthly->pluck('index')->map(fn ($v) => $v === null ? null : round($v, 2)), 'fill' => true]]]) }}"></canvas>
            </div>
        </div>
        <div class="card overflow-x-auto p-0">
            <h2 class="card-title px-5 pt-5 pb-2">Mes a mes</h2>
            <table class="table">
                <thead><tr><th>Mes</th><th class="text-right">Índice</th><th class="text-right">Var.</th></tr></thead>
                <tbody>
                @foreach ($monthly->reverse() as $row)
                    <tr>
                        <td>{{ $row->label }}</td>
                        <td class="text-right tabular-nums">{{ $row->index === null ? '—' : number_format($row->index, 1) }}</td>
                        <td class="text-right tabular-nums">
                            @if ($row->change !== null && abs($row->change) > 0.00005)
                                <span class="badge {{ $row->change > 0 ? 'badge-up' : 'badge-down' }}">{{ pct($row->change) }}</span>
                            @else
                                <span class="text-slate-300">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card overflow-x-auto p-0">
        <h2 class="card-title px-5 pt-5 pb-2">Variación por artículo</h2>
        <table class="table">
            <thead>
            <tr><th>Artículo</th><th>Periodo</th><th class="text-right">Antes</th><th class="text-right">Ahora</th><th class="text-right">Variación</th><th>Por proveedor</th></tr>
            </thead>
            <tbody>
            @foreach ($products as $row)
                <tr>
                    <td><a class="font-medium hover:text-emerald-700" href="{{ route('products.show', $row->product) }}">{{ $row->product->name }}</a>
                        <div class="text-xs text-slate-500">{{ $row->product->sizeLabel() }}</div></td>
                    <td class="text-xs whitespace-nowrap text-slate-500">{{ \Carbon\Carbon::parse($row->from)->translatedFormat('d M y') }} → {{ \Carbon\Carbon::parse($row->to)->translatedFormat('d M y') }}</td>
                    <td class="text-right tabular-nums text-slate-500">{{ money($row->first_price) }}{{ $row->compare_label }}</td>
                    <td class="text-right font-medium tabular-nums">{{ money($row->last_price) }}{{ $row->compare_label }}</td>
                    <td class="text-right">
                        <span class="badge {{ $row->change > 0 ? 'badge-up' : ($row->change < 0 ? 'badge-down' : 'badge-muted') }}">{{ pct($row->change) }}</span>
                    </td>
                    <td class="text-xs text-slate-500">
                        @foreach ($row->providers as $p)
                            <span class="mr-2 whitespace-nowrap">{{ $p->provider->name }} <span class="{{ $p->change > 0 ? 'text-red-600' : 'text-emerald-700' }}">{{ pct($p->change, 0) }}</span></span>
                        @endforeach
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
