@extends('layouts.app')
@section('title', 'Ingresos y pagos')

@section('content')
@php
    $r = $report;
    $month = $r['month'];
    $monthLink = fn (\Carbon\CarbonInterface $m) => route('incomes.index', array_filter(['month' => $m->format('Y-m'), 'tab' => $tab === 'incomes' ? null : $tab]));
@endphp
<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-3xl font-bold text-slate-900">Ingresos y pagos</h1>
        <p class="text-sm text-slate-500">Lo que entra y lo que sale, recurrente o una sola vez: el sueldo y la luz se repiten; una venta o la gasolina, no. Los ingresos pueden ser en especie (vales, un regalo), con su valor.</p>
    </div>
    <a href="{{ route('debts.index') }}" class="btn btn-secondary"><x-icon name="credit-card" class="size-4" /> Deudas</a>
</div>

<div id="incomes-results" data-live-results>
{{-- Mes --}}
<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ $monthLink($month->subMonth()) }}" class="icon-btn" aria-label="Mes anterior"><x-icon name="chevron-left" class="size-5" /></a>
    <h2 class="font-display text-xl font-semibold text-slate-900 capitalize">{{ $month->translatedFormat('F Y') }}</h2>
    <a href="{{ $monthLink($month->addMonth()) }}" class="icon-btn" aria-label="Mes siguiente"><x-icon name="chevron-right" class="size-5" /></a>
    @unless ($isCurrentMonth)
        <a href="{{ route('incomes.index', array_filter(['tab' => $tab === 'incomes' ? null : $tab])) }}" class="btn btn-ghost btn-sm">Este mes</a>
    @endunless
</div>

<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <div class="card">
        <div class="stat-label">Ingresos del mes</div>
        <div class="stat-value">{{ money($r['total']) }}</div>
        <div class="mt-1 text-xs text-slate-500">{{ money($r['money']) }} dinero · {{ money($r['inKind']) }} en especie</div>
    </div>
    <div class="card">
        <div class="stat-label">Gastado en el mes</div>
        <div class="stat-value">{{ money($r['spent']) }}</div>
        <div class="mt-1 text-xs text-slate-500">{{ money($r['super']) }} súper · {{ money($r['payments']) }} pagos</div>
    </div>
    <div class="card">
        <div class="stat-label">Balance del mes</div>
        <div class="stat-value {{ $r['balance'] < 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ money($r['balance']) }}</div>
        <div class="mt-1 text-xs text-slate-500">ingresos − súper − pagos</div>
    </div>
    <div class="card">
        <div class="stat-label">Recurrentes al mes</div>
        <div class="stat-value {{ $expected['total'] - $expectedPayments < 0 ? 'text-red-700' : '' }}">{{ money($expected['total'] - $expectedPayments) }}</div>
        <div class="mt-1 text-xs text-slate-500">entran {{ money($expected['total']) }} · salen {{ money($expectedPayments) }}</div>
    </div>
</div>

{{-- Pestañas: lo que entra y lo que sale --}}
<nav class="mb-4 flex flex-wrap gap-2" aria-label="Ingresos o pagos">
    @foreach (\App\Http\Controllers\IncomeController::TABS as $key => $label)
        <a href="{{ route('incomes.index', array_filter(['tab' => $key === 'incomes' ? null : $key, 'month' => $isCurrentMonth ? null : $month->format('Y-m')])) }}"
           class="chip {{ $tab === $key ? 'is-active' : '' }}" @if ($tab === $key) aria-current="page" @endif>
            <x-icon :name="$key === 'incomes' ? 'banknote' : 'receipt-text'" class="size-4" /> {{ $label }}
            <span class="opacity-70">{{ $key === 'incomes' ? $r['incomes']->count() : $r['paid']->count() }}</span>
        </a>
    @endforeach
</nav>

@include($tab === 'payments' ? 'incomes._payments-tab' : 'incomes._incomes-tab')
</div>
@endsection
