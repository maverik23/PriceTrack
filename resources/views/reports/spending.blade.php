@extends('layouts.app')
@section('title', 'Gasto promedio')

@section('content')
<h1 class="text-2xl font-semibold text-slate-900">Gasto promedio</h1>
<p class="mb-4 text-sm text-slate-500">Basado en los artículos marcados como comprados en tus listas (fecha de la lista × precio elegido).</p>

@include('reports._range')

{{-- Por qué algo no aparece: lo más común es no marcar dónde se compró --}}
@if ($gaps['unmarked'] || $gaps['future'])
    <div class="mb-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
        <p class="font-semibold">No todo lo que capturaste cuenta como gasto:</p>
        <ul class="mt-1 list-inside list-disc space-y-0.5">
            @if ($gaps['unmarked'])
                <li>{{ $gaps['unmarked'] }} {{ $gaps['unmarked'] === 1 ? 'artículo' : 'artículos' }} de listas de este periodo sin marcar como comprado. En la lista, toca el círculo junto al precio de la tienda donde lo compraste.</li>
            @endif
            @if ($gaps['future'])
                <li>{{ $gaps['future'] }} {{ $gaps['future'] === 1 ? 'compra está' : 'compras están' }} en listas con fecha futura; cuentan a partir de esa fecha. Cambia la fecha de la lista si ya compraste.</li>
            @endif
        </ul>
        <p class="mt-1 text-xs text-amber-800">Los precios de «Capturar precio» sirven para comparar; el gasto sale solo de lo marcado como comprado en tus listas.</p>
    </div>
@endif

@if ($total <= 0)
    <div class="card py-12 text-center text-slate-500">No hay compras registradas en este periodo. Marca artículos como «Comprado aquí» en tus listas.</div>
@else
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="card">
            <div class="stat-label">Total del periodo</div>
            <div class="stat-value">{{ money($total) }}</div>
            <div class="mt-1 text-xs text-slate-500">{{ $span }} {{ $span === 1 ? "día" : "días" }} · {{ $lists }} compras</div>
        </div>
        <div class="card">
            <div class="stat-label">Promedio semanal</div>
            <div class="stat-value">{{ money($weekly) }}</div>
        </div>
        <div class="card">
            <div class="stat-label">Promedio mensual</div>
            <div class="stat-value">{{ money($monthly) }}</div>
        </div>
        <div class="card">
            <div class="stat-label">Promedio anual {{ $days < 365 ? '(proyectado)' : '' }}</div>
            <div class="stat-value">{{ money($annual) }}</div>
        </div>
        <div class="card">
            <div class="stat-label">Ticket promedio por lista</div>
            <div class="stat-value">{{ money($perList) }}</div>
        </div>
    </div>
    <p class="-mt-3 mb-6 text-xs text-slate-400">
        Promedios = gasto total ÷ {{ $days }} días (del {{ $start->translatedFormat('d M Y') }} al {{ $end->translatedFormat('d M Y') }}{{ $span < $days ? '; mínimo una semana' : '' }}) × 7 / 30.4 / 365.
    </p>

    <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="card">
            <h2 class="card-title mb-3">Gasto por mes</h2>
            <div class="h-64">
                <canvas data-chart="{{ json_encode(['type' => 'bar', 'format' => 'money', 'labels' => $byMonth->pluck('label'), 'datasets' => [['label' => 'Gasto', 'data' => $byMonth->pluck('amount')->map(fn ($v) => round($v, 2))]]]) }}"></canvas>
            </div>
        </div>
        <div class="card">
            <h2 class="card-title mb-3">Gasto por semana</h2>
            <div class="h-64">
                <canvas data-chart="{{ json_encode(['type' => 'bar', 'format' => 'money', 'labels' => $byWeek->pluck('label'), 'datasets' => [['label' => 'Gasto', 'data' => $byWeek->pluck('amount')->map(fn ($v) => round($v, 2))]]]) }}"></canvas>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="card overflow-x-auto p-0">
            <h2 class="card-title px-5 pt-5 pb-2">Por año</h2>
            <table class="table">
                <thead><tr><th>Año</th><th class="text-right">Compras</th><th class="text-right">Total</th></tr></thead>
                <tbody>
                @foreach ($byYear as $row)
                    <tr><td>{{ $row->year }}</td><td class="text-right tabular-nums">{{ $row->lists }}</td><td class="text-right font-semibold tabular-nums">{{ money($row->amount) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="card overflow-x-auto p-0">
            <h2 class="card-title px-5 pt-5 pb-2">Por proveedor</h2>
            <table class="table">
                <thead><tr><th>Proveedor</th><th class="text-right">%</th><th class="text-right">Total</th></tr></thead>
                <tbody>
                @foreach ($byProvider as $row)
                    <tr>
                        <td>{{ $row->name }}
                            <div class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" style="width: {{ round($row->share * 100) }}%"></div></div>
                        </td>
                        <td class="text-right tabular-nums text-slate-500">{{ number_format($row->share * 100, 1) }}%</td>
                        <td class="text-right font-semibold tabular-nums">{{ money($row->amount) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="card overflow-x-auto p-0">
            <h2 class="card-title px-5 pt-5 pb-2">Por categoría</h2>
            <table class="table">
                <thead><tr><th>Categoría</th><th class="text-right">%</th><th class="text-right">Total</th></tr></thead>
                <tbody>
                @foreach ($byCategory as $row)
                    <tr>
                        <td>{{ $row->name }}
                            <div class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-full rounded-full bg-blue-500" style="width: {{ round($row->share * 100) }}%"></div></div>
                        </td>
                        <td class="text-right tabular-nums text-slate-500">{{ number_format($row->share * 100, 1) }}%</td>
                        <td class="text-right font-semibold tabular-nums">{{ money($row->amount) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mt-6 overflow-x-auto p-0">
        <h2 class="card-title px-5 pt-5 pb-2">Detalle mensual</h2>
        <table class="table">
            <thead><tr><th>Mes</th><th class="text-right">Total</th><th class="text-right">vs. mes anterior</th></tr></thead>
            <tbody>
            @foreach ($byMonth as $i => $row)
                @php($prev = $i > 0 ? $byMonth[$i - 1]->amount : null)
                <tr>
                    <td>{{ $row->label }}</td>
                    <td class="text-right font-semibold tabular-nums">{{ money($row->amount) }}</td>
                    <td class="text-right tabular-nums">
                        @if ($prev)
                            @php($delta = $row->amount / $prev - 1)
                            <span class="badge {{ $delta > 0 ? 'badge-up' : 'badge-down' }}">{{ pct($delta) }}</span>
                        @else
                            <span class="text-slate-300">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
