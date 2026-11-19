@extends('layouts.app')
@section('title', 'Patrimonio')

@section('content')
@php
    $change = fn ($then) => $then && $then['total'] > 0 ? ($now['total'] - $then['total']) / $then['total'] : null;
    $labels = $series->map(fn ($p) => $p['date']->translatedFormat('M y'))->all();
@endphp
<div class="mb-6">
    <h1 class="text-2xl font-semibold text-slate-900">Patrimonio neto</h1>
    <p class="text-sm text-slate-500">Tus inversiones más el valor de tus bienes, menos tus deudas. En pesos.</p>
</div>

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="card">
        <div class="stat-label">Patrimonio hoy</div>
        <div class="stat-value">{{ money($now['total']) }}</div>
    </div>
    <a href="{{ route('investments.index') }}" class="card hover:ring-emerald-300">
        <div class="stat-label">Inversiones</div>
        <div class="stat-value">{{ money($now['investments']) }}</div>
    </a>
    <a href="{{ route('assets.index') }}" class="card hover:ring-emerald-300">
        <div class="stat-label">Bienes</div>
        <div class="stat-value">{{ money($now['assets']) }}</div>
        @if ($now['debts'] > 0)
            <div class="mt-1 text-xs text-red-600">Deudas: −{{ money($now['debts']) }}</div>
        @endif
    </a>
    <div class="card">
        <div class="stat-label">Cambio</div>
        @foreach (['En el mes' => $monthAgo, 'En 12 meses' => $yearAgo] as $label => $then)
            @php($c = $change($then))
            <div class="mt-1 flex items-baseline justify-between text-sm">
                <span class="text-slate-500">{{ $label }}</span>
                <span class="font-semibold tabular-nums {{ $c === null ? '' : ($c >= 0 ? 'text-emerald-700' : 'text-red-600') }}">{{ $then ? money($now['total'] - $then['total'], 0) : '—' }} <span class="text-xs">{{ pct($c) }}</span></span>
            </div>
        @endforeach
    </div>
</div>

<section class="card mb-6">
    <h2 class="card-title mb-3">Últimos 24 meses</h2>
    <div class="h-72">
        <canvas data-chart="{{ json_encode(['type' => 'line', 'format' => 'money', 'labels' => $labels, 'datasets' => [
            ['label' => 'Patrimonio', 'data' => $series->pluck('total')->all(), 'borderWidth' => 3],
            ['label' => 'Bienes', 'data' => $series->pluck('assets')->all()],
            ['label' => 'Inversiones', 'data' => $series->pluck('investments')->all()],
            ...($series->max('debts') > 0 ? [['label' => 'Deudas', 'data' => $series->pluck('debts')->all(), 'borderColor' => '#dc2626', 'backgroundColor' => '#dc262622']] : []),
        ]]) }}"></canvas>
    </div>
</section>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <section class="card overflow-x-auto p-0">
        <h2 class="card-title px-5 pt-5 pb-2">Inversiones</h2>
        <table class="table">
            <tbody>
            @forelse ($accounts->sortByDesc(fn ($a) => $a->currentValue()) as $account)
                <tr>
                    <td><a href="{{ route('investments.show', $account) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $account->name }}</a></td>
                    <td class="text-right tabular-nums">{{ money($account->currentValue()) }}</td>
                    <td class="w-20 text-right text-xs text-slate-500 tabular-nums">{{ $now['total'] > 0 ? number_format($account->currentValue() / $now['total'] * 100, 1).' %' : '' }}</td>
                </tr>
            @empty
                <tr><td class="py-6 text-center text-sm text-slate-500">Sin cuentas. <a class="link" href="{{ route('investments.create') }}">Agrega una</a>.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
    <section class="card overflow-x-auto p-0">
        <h2 class="card-title px-5 pt-5 pb-2">Bienes</h2>
        <table class="table">
            <tbody>
            @forelse ($assets->sortByDesc(fn ($a) => $a->currentValue() ?? 0) as $asset)
                <tr>
                    <td><a href="{{ route('assets.show', $asset) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $asset->name }}</a></td>
                    <td class="text-right tabular-nums">{{ money($asset->currentValue()) }}</td>
                    <td class="w-20 text-right text-xs text-slate-500 tabular-nums">{{ $now['total'] > 0 ? number_format(($asset->currentValue() ?? 0) / $now['total'] * 100, 1).' %' : '' }}</td>
                </tr>
            @empty
                <tr><td class="py-6 text-center text-sm text-slate-500">Sin bienes. <a class="link" href="{{ route('assets.create') }}">Agrega uno</a>.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
    <section class="card overflow-x-auto p-0">
        <h2 class="card-title px-5 pt-5 pb-2">Deudas</h2>
        <table class="table">
            <tbody>
            @forelse ($debts->sortByDesc(fn ($d) => $d->currentBalance()) as $debt)
                <tr>
                    <td><a href="{{ route('debts.show', $debt) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $debt->name }}</a></td>
                    <td class="text-right text-red-600 tabular-nums">−{{ money($debt->currentBalance()) }}</td>
                </tr>
            @empty
                <tr><td class="py-6 text-center text-sm text-slate-500">Sin deudas. <a class="link" href="{{ route('debts.create') }}">Agrega una</a> si tienes.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
