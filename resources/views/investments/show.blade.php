@extends('layouts.app')
@section('title', $account->name)

@section('content')
@php
    $gain = $account->gain();
    $annual = $account->annualizedReturn();
    $simple = $account->simpleReturn();
    $last = $account->lastValuation();
    $foreign = $account->isForeign();
@endphp
<a href="{{ route('investments.index') }}" class="link text-sm">← Inversiones</a>
<div class="mt-2 mb-6 flex flex-wrap items-start justify-between gap-3">
    <div class="min-w-0">
        <h1 class="text-2xl font-semibold break-words text-slate-900">{{ $account->name }}</h1>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
            <span class="badge badge-muted">{{ $account->typeLabel() }}</span>
            {{ collect([$account->institution, $foreign ? 'en '.$account->currency : null])->filter()->join(' · ') }}
            @if ($account->closed_on)<span class="badge badge-up">Cerrada el {{ $account->closed_on->translatedFormat('d M Y') }}</span>@endif
        </p>
    </div>
    <a href="{{ route('investments.edit', $account) }}" class="btn btn-secondary">Editar</a>
</div>

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="card">
        <div class="stat-label">Valor</div>
        <div class="stat-value">{{ money($account->currentValue()) }}</div>
        <div class="mt-1 text-xs {{ $account->isStale() ? 'text-amber-700' : 'text-slate-500' }}">
            {{ $last ? 'Capturado el '.$last->valued_on->translatedFormat('d M Y') : 'Sin capturar: se toma lo aportado' }}@if ($last && $foreign) · {{ number_format($last->value, 2) }} {{ $account->currency }} a {{ num($last->exchange_rate) }} @endif
        </div>
    </div>
    <div class="card">
        <div class="stat-label">Aportado neto</div>
        <div class="stat-value">{{ money($account->netContributed()) }}</div>
        <div class="mt-1 text-xs text-slate-500">Dividendos e intereses: {{ money($account->totalOf('income')) }} · comisiones: {{ money($account->totalOf('fee')) }}</div>
    </div>
    <div class="card">
        <div class="stat-label">Ganancia</div>
        <div class="stat-value {{ $gain >= 0 ? 'text-emerald-700' : 'text-red-600' }}">{{ ($gain >= 0 ? '+' : '').money($gain) }}</div>
        <div class="mt-1 text-xs text-slate-500">{{ pct($simple) }} sobre lo aportado</div>
    </div>
    <div class="card">
        <div class="stat-label">Rendimiento anual</div>
        <div class="stat-value">{{ pct($annual) }}</div>
        <div class="mt-1 text-xs text-slate-500">ponderado por dinero (TIR), al {{ $account->asOf()->translatedFormat('d M Y') }}</div>
    </div>
</div>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="min-w-0 space-y-6 lg:col-span-2">
        <section class="card">
            <h2 class="card-title mb-3">Valor y aportado</h2>
            @if (count($series[0]['data']) > 1)
                <div class="h-64">
                    <canvas data-chart="{{ json_encode(['type' => 'line', 'timeAxis' => true, 'format' => 'money', 'datasets' => $series]) }}"></canvas>
                </div>
            @else
                <p class="py-10 text-center text-sm text-slate-400">Registra movimientos y valores para ver la gráfica.</p>
            @endif
        </section>

        <section class="card overflow-x-auto p-0">
            <h2 class="card-title px-5 pt-5 pb-2">Movimientos</h2>
            <form method="POST" action="{{ route('investments.transactions.store', $account) }}" class="grid grid-cols-1 gap-3 border-b border-slate-100 px-5 pb-5 sm:grid-cols-6">
                @csrf
                                <div class="sm:col-span-2">
                    <label class="label" for="tx-kind">Tipo</label>
                    <select id="tx-kind" name="kind" class="input">
                        @foreach ($kinds as $value => $label)
                            <option value="{{ $value }}" @selected(old('kind', 'deposit') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="tx-date">Fecha</label>
                    <input id="tx-date" name="occurred_on" type="date" value="{{ old('occurred_on', today()->toDateString()) }}" max="{{ today()->toDateString() }}" class="input" required>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="tx-amount">Monto ({{ $account->currency }})</label>
                    <input id="tx-amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" class="input" required>
                </div>
                @if ($foreign)
                    <div class="sm:col-span-2">
                        <label class="label" for="tx-rate">Tipo de cambio (MXN por {{ $account->currency }})</label>
                        <input id="tx-rate" name="exchange_rate" type="number" step="0.0001" min="0.0001" value="{{ old('exchange_rate') }}" class="input" required>
                    </div>
                @endif
                <div class="{{ $foreign ? 'sm:col-span-4' : 'sm:col-span-6' }}">
                    <label class="label" for="tx-notes">Notas</label>
                    <input id="tx-notes" name="notes" value="{{ old('notes') }}" class="input" maxlength="255">
                </div>
                <p class="text-xs text-slate-500 sm:col-span-6">Los dividendos, intereses y comisiones se quedan en la cuenta (ya están en su valor) y no cuentan como aportación. Si te los depositan fuera, regístralos también como retiro.</p>
                <div class="sm:col-span-6"><button class="btn btn-primary">Registrar</button></div>
            </form>
            <table class="table">
                <thead><tr><th>Fecha</th><th>Tipo</th><th class="text-right">Monto</th>@if ($foreign)<th class="text-right">En pesos</th>@endif<th></th></tr></thead>
                <tbody>
                @forelse ($account->transactions->reverse() as $transaction)
                    <tr>
                        <td class="whitespace-nowrap text-slate-500">{{ $transaction->occurred_on->translatedFormat('d M Y') }}</td>
                        <td>{{ $transaction->kindLabel() }} @if ($transaction->notes)<div class="text-xs text-slate-500">{{ $transaction->notes }}</div>@endif</td>
                        <td class="text-right tabular-nums {{ $transaction->kind === 'withdrawal' || $transaction->kind === 'fee' ? 'text-red-600' : '' }}">
                            {{ $transaction->kind === 'withdrawal' || $transaction->kind === 'fee' ? '−' : '' }}{{ $foreign ? number_format($transaction->amount, 2).' '.$account->currency : money($transaction->amount) }}
                        </td>
                        @if ($foreign)<td class="text-right tabular-nums">{{ money($transaction->amountMxn()) }} <div class="text-xs text-slate-500">a {{ num($transaction->exchange_rate) }}</div></td>@endif
                        <td class="text-right">
                            <form method="POST" action="{{ route('investments.transactions.destroy', [$account, $transaction]) }}" onsubmit="return confirm('¿Eliminar el movimiento?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm" title="Eliminar movimiento">✕</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-sm text-slate-500">Sin movimientos. Registra tu primera aportación abajo.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    </div>

    <div class="min-w-0 space-y-6">
        <section class="card">
            <h2 class="card-title">Capturas de valor</h2>
            <form method="POST" action="{{ route('investments.valuations.store', $account) }}" class="mt-3 grid grid-cols-2 gap-2">
                @csrf
                <input name="valued_on" type="date" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" class="input" required aria-label="Fecha del valor">
                <input name="value" type="number" step="0.01" min="0" class="input" placeholder="Valor {{ $account->currency }}" required aria-label="Valor">
                @if ($foreign)
                    <input name="exchange_rate" type="number" step="0.0001" min="0.0001" class="input col-span-2" placeholder="Tipo de cambio (MXN por {{ $account->currency }})" required aria-label="Tipo de cambio">
                @endif
                <button class="btn btn-secondary col-span-2">Capturar valor</button>
            </form>
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @foreach ($account->valuations->reverse() as $valuation)
                    <li class="flex items-center justify-between gap-2 py-1.5">
                        <span class="text-slate-500">{{ $valuation->valued_on->translatedFormat('d M Y') }}</span>
                        <span class="flex items-center gap-1 text-right tabular-nums">
                            <span>{{ money($valuation->valueMxn()) }}@if ($foreign)<span class="block text-xs text-slate-500">{{ number_format($valuation->value, 2) }} {{ $account->currency }} a {{ num($valuation->exchange_rate) }}</span>@endif</span>
                            <form method="POST" action="{{ route('investments.valuations.destroy', [$account, $valuation]) }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm" title="Eliminar captura">✕</button>
                            </form>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
        @if ($account->notes)
            <section class="card text-sm text-slate-600">{{ $account->notes }}</section>
        @endif
    </div>
</div>
@endsection
