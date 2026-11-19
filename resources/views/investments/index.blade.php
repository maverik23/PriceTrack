@extends('layouts.app')
@section('title', 'Inversiones')

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Inversiones</h1>
        <p class="text-sm text-slate-500">Captura tus aportaciones y, de vez en cuando, cuánto vale cada cuenta. Todo en pesos.</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('net-worth') }}" class="btn btn-secondary">Patrimonio</a>
        <a href="{{ route('investments.create') }}" class="btn btn-primary">+ Nueva cuenta</a>
    </div>
</div>

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="card">
        <div class="stat-label">Valor de tus inversiones</div>
        <div class="stat-value">{{ money($summary['value']) }}</div>
    </div>
    <div class="card">
        <div class="stat-label">Aportado neto</div>
        <div class="stat-value">{{ money($summary['contributed']) }}</div>
        <div class="mt-1 text-xs text-slate-500">aportaciones − retiros</div>
    </div>
    <div class="card">
        <div class="stat-label">Ganancia</div>
        <div class="stat-value {{ $summary['gain'] >= 0 ? 'text-emerald-700' : 'text-red-600' }}">{{ ($summary['gain'] >= 0 ? '+' : '').money($summary['gain']) }}</div>
    </div>
    <div class="card">
        <div class="stat-label">Rendimiento anual (todas)</div>
        <div class="stat-value">{{ pct($summary['annualized']) }}</div>
        <div class="mt-1 text-xs text-slate-500">ponderado por dinero (TIR)</div>
    </div>
</div>

@if ($summary['stale']->isNotEmpty())
    <div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">
        Sin valor capturado en {{ \App\Models\InvestmentAccount::STALE_DAYS }} días: {{ $summary['stale']->pluck('name')->join(', ') }}. Captura su valor de hoy para que el rendimiento esté al día.
    </div>
@endif

<div class="card overflow-x-auto p-0">
    <table class="table">
        <thead>
        <tr><th>Cuenta</th><th class="text-right">Valor</th><th class="text-right">Aportado neto</th><th class="text-right">Ganancia</th><th class="text-right">Rend. anual</th><th>Último valor</th></tr>
        </thead>
        <tbody>
        @forelse ($accounts as $account)
            @php($gain = $account->gain())
            <tr class="{{ $account->isClosed() ? 'text-slate-400' : '' }}">
                <td>
                    <a href="{{ route('investments.show', $account) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $account->name }}</a>
                    <div class="text-xs text-slate-500">{{ collect([$account->institution, $account->typeLabel(), $account->isForeign() ? $account->currency : null, $account->isClosed() ? 'Cerrada' : null])->filter()->join(' · ') }}</div>
                </td>
                <td class="text-right whitespace-nowrap tabular-nums">{{ money($account->currentValue()) }}</td>
                <td class="text-right whitespace-nowrap tabular-nums">{{ money($account->netContributed()) }}</td>
                <td class="text-right whitespace-nowrap tabular-nums {{ $gain >= 0 ? 'text-emerald-700' : 'text-red-600' }}">{{ ($gain >= 0 ? '+' : '').money($gain) }}</td>
                <td class="text-right whitespace-nowrap tabular-nums">{{ pct($account->annualizedReturn()) }}</td>
                <td class="text-sm whitespace-nowrap {{ $account->isStale() ? 'text-amber-700' : 'text-slate-500' }}">{{ $account->closed_on ? 'Cerrada el '.$account->closed_on->translatedFormat('d M Y') : ($account->lastValuation()?->valued_on->translatedFormat('d M Y') ?? 'Sin capturar') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-10 text-center text-slate-500">Sin cuentas todavía. <a class="link" href="{{ route('investments.create') }}">Agrega la primera</a>: CETES, tu Afore, un fondo…</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
