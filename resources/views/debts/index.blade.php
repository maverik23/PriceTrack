@extends('layouts.app')
@section('title', 'Deudas')

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-3xl font-bold text-slate-900">Deudas</h1>
        <p class="text-sm text-slate-500">Lo que debes, cuánto pagas al mes y cuánto te falta. Se resta en tu patrimonio.</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('recurring.index') }}" class="btn btn-secondary">Pagos recurrentes</a>
        <a href="{{ route('debts.create') }}" class="btn btn-primary">+ Nueva deuda</a>
    </div>
</div>

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div class="card">
        <div class="stat-label">Debes hoy</div>
        <div class="stat-value {{ $total > 0 ? 'text-red-600' : '' }}">{{ money($total) }}</div>
    </div>
    <div class="card">
        <div class="stat-label">Pagas al mes</div>
        <div class="stat-value">{{ money($monthly) }}</div>
        <div class="mt-1 text-xs text-slate-500">mensualidades ligadas a tus deudas en <a class="link" href="{{ route('recurring.index') }}">Pagos recurrentes</a></div>
    </div>
</div>

<div class="card overflow-x-auto p-0">
    <table class="table">
        <thead>
        <tr><th>Deuda</th><th class="text-right">Saldo</th><th class="text-right">Pagado</th><th class="text-right">Al mes</th><th class="text-right">Faltan</th></tr>
        </thead>
        <tbody>
        @forelse ($debts as $debt)
            @php($left = $debt->monthsLeft())
            <tr class="{{ $debt->isPaidOff() ? 'text-slate-400' : '' }}">
                <td>
                    <a href="{{ route('debts.show', $debt) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $debt->name }}</a>
                    <div class="text-xs text-slate-500">{{ collect([$debt->lender, $debt->typeLabel(), $debt->annual_rate ? num($debt->annual_rate).' % anual' : null, $debt->isPaidOff() ? 'Liquidada' : null])->filter()->join(' · ') }}</div>
                </td>
                <td class="text-right whitespace-nowrap tabular-nums">
                    {{ money($debt->currentBalance()) }}
                    @if ($debt->original_amount > 0 && ! $debt->isPaidOff())
                        <div class="mt-1 ml-auto h-1.5 w-24 overflow-hidden rounded-full bg-slate-100" title="{{ number_format(100 - $debt->currentBalance() / $debt->original_amount * 100, 0) }} % pagado">
                            <div class="h-full bg-emerald-500" style="width: {{ max(0, min(100, 100 - $debt->currentBalance() / $debt->original_amount * 100)) }}%"></div>
                        </div>
                    @endif
                </td>
                <td class="text-right whitespace-nowrap tabular-nums">{{ money($debt->totalPaid()) }}</td>
                <td class="text-right whitespace-nowrap tabular-nums">{{ $debt->monthlyPayment() ? money($debt->monthlyPayment()) : '—' }}</td>
                <td class="text-right text-sm whitespace-nowrap">
                    @if ($debt->isPaidOff())
                        Liquidada
                    @elseif ($left === 0)
                        <span class="text-emerald-700">Pagada</span>
                        <div class="text-xs text-slate-500">ponle fecha de liquidación</div>
                    @elseif ($left === null)
                        <span class="text-slate-400" title="Sin mensualidad o no alcanza para el interés">—</span>
                    @else
                        {{ $left }} {{ $left === 1 ? 'mes' : 'meses' }}
                        <div class="text-xs text-slate-500">{{ today()->addMonths($left)->translatedFormat('M Y') }}</div>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-10 text-center text-slate-500">Sin deudas. 🎉 Si tienes alguna, <a class="link" href="{{ route('debts.create') }}">agrégala</a> para verla en tu patrimonio.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
