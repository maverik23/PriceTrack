@extends('layouts.app')
@section('title', $debt->name)

@section('content')
@php
    $balance = $debt->currentBalance();
    $left = $debt->monthsLeft();
@endphp
<a href="{{ route('debts.index') }}" class="link text-sm">← Deudas</a>
<div class="mt-2 mb-6 flex flex-wrap items-start justify-between gap-3">
    <div class="min-w-0">
        <h1 class="text-2xl font-semibold break-words text-slate-900">{{ $debt->name }}</h1>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
            <span class="badge badge-muted">{{ $debt->typeLabel() }}</span>
            {{ collect([$debt->lender, $debt->annual_rate ? num($debt->annual_rate).' % anual' : 'sin intereses', $debt->started_on ? 'desde '.$debt->started_on->translatedFormat('d M Y') : null])->filter()->join(' · ') }}
            @if ($debt->paid_off_on)<span class="badge badge-down">Liquidada el {{ $debt->paid_off_on->translatedFormat('d M Y') }}</span>@endif
        </p>
    </div>
    <a href="{{ route('debts.edit', $debt) }}" class="btn btn-secondary">Editar</a>
</div>

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="card">
        <div class="stat-label">Saldo hoy</div>
        <div class="stat-value {{ $balance > 0 ? 'text-red-600' : 'text-emerald-700' }}">{{ money($balance) }}</div>
        <div class="mt-1 text-xs text-slate-500">de {{ money($debt->original_amount) }}</div>
    </div>
    <div class="card">
        <div class="stat-label">Pagado</div>
        <div class="stat-value">{{ money($debt->totalPaid()) }}</div>
        <div class="mt-1 text-xs text-slate-500">{{ $debt->payments->count() }} pagos registrados</div>
    </div>
    <div class="card">
        <div class="stat-label">Pagas al mes</div>
        <div class="stat-value">{{ $debt->monthlyPayment() ? money($debt->monthlyPayment()) : '—' }}</div>
        <div class="mt-1 text-xs text-slate-500">
            @forelse ($debt->recurringPayments as $recurring)
                <a class="link" href="{{ route('recurring.edit', $recurring) }}">{{ $recurring->name }}</a>@if (! $loop->last), @endif
            @empty
                <a class="link" href="{{ route('recurring.index') }}">Agrega su mensualidad</a>
            @endforelse
        </div>
    </div>
    <div class="card">
        <div class="stat-label">Te faltan</div>
        <div class="stat-value">{{ $left === null ? '—' : $left.' '.($left === 1 ? 'mes' : 'meses') }}</div>
        <div class="mt-1 text-xs text-slate-500">{{ $left ? 'Terminas en '.today()->addMonths($left)->translatedFormat('F Y').' con el pago de hoy' : ($left === 0 ? 'Pagada' : 'Necesita una mensualidad mayor que el interés') }}</div>
    </div>
</div>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="min-w-0 space-y-6 lg:col-span-2">
        <section class="card">
            <h2 class="card-title mb-3">Saldo en el tiempo</h2>
            @if (count($series) > 1)
                <div class="h-64">
                    <canvas data-chart="{{ json_encode(['type' => 'line', 'timeAxis' => true, 'format' => 'money', 'beginAtZero' => true, 'datasets' => [['label' => 'Saldo', 'data' => $series, 'fill' => true, 'pointRadius' => 0, 'borderColor' => '#dc2626', 'backgroundColor' => '#dc262622']]]) }}"></canvas>
                </div>
            @else
                <p class="py-10 text-center text-sm text-slate-400">Pon la fecha de inicio o registra pagos para ver la gráfica.</p>
            @endif
        </section>

        <section class="card overflow-x-auto p-0">
            <h2 class="card-title px-5 pt-5 pb-2">Pagos</h2>
            <form method="POST" action="{{ route('debts.payments.store', $debt) }}" class="grid grid-cols-1 gap-3 border-b border-slate-100 px-5 pb-5 sm:grid-cols-6">
                @csrf
                <div class="sm:col-span-2">
                    <label class="label" for="pay-date">Fecha</label>
                    <input id="pay-date" name="paid_on" type="date" value="{{ old('paid_on', today()->toDateString()) }}" max="{{ today()->toDateString() }}" class="input" required>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="pay-amount">Monto</label>
                    <input id="pay-amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" class="input" required placeholder="$">
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="pay-notes">Notas</label>
                    <input id="pay-notes" name="notes" value="{{ old('notes') }}" class="input" maxlength="255" placeholder="Ej. Abono a capital">
                </div>
                <div class="flex flex-wrap items-center gap-3 sm:col-span-6">
                    <button class="btn btn-primary">Registrar pago</button>
                    <span class="text-xs text-slate-500">Para pagos sueltos o abonos. La mensualidad márcala como pagada en Pagos recurrentes.</span>
                </div>
            </form>
            @php($recentPayments = $debt->payments->reverse()->values())
            @foreach ([$recentPayments->take(12), $recentPayments->slice(12)] as $chunk => $rows)
                @continue($chunk === 1 && $rows->isEmpty())
                @if ($chunk === 1)
                    <details class="border-t border-slate-100">
                    <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-600">Pagos anteriores ({{ $rows->count() }})</summary>
                @endif
                <table class="table">
                    @if ($chunk === 0)<thead><tr><th>Fecha</th><th>Concepto</th><th class="text-right">Monto</th><th></th></tr></thead>@endif
                    <tbody>
                    @forelse ($rows as $payment)
                        <tr>
                            <td class="whitespace-nowrap text-slate-500">{{ $payment->paid_on->translatedFormat('d M Y') }}</td>
                            <td>{{ $payment->recurringPayment?->name ?? 'Pago suelto' }} @if ($payment->notes)<div class="text-xs text-slate-500">{{ $payment->notes }}</div>@endif</td>
                            <td class="text-right tabular-nums">{{ money($payment->amount) }}</td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('debts.payments.destroy', [$debt, $payment]) }}" onsubmit="return confirm('¿Eliminar el pago?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-danger btn-sm" title="Eliminar pago">✕</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-sm text-slate-500">Sin pagos registrados.</td></tr>
                    @endforelse
                    </tbody>
                </table>
                @if ($chunk === 1)
                    </details>
                @endif
            @endforeach
        </section>
    </div>

    <div class="min-w-0 space-y-6">
        <section class="card">
            <h2 class="card-title">Saldo según el estado de cuenta</h2>
            <p class="mt-1 text-xs text-slate-500">Desde la última captura el saldo se calcula con el interés y tus pagos.</p>
            <form method="POST" action="{{ route('debts.balances.store', $debt) }}" class="mt-3 grid grid-cols-2 gap-2">
                @csrf
                <input name="balance_on" type="date" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" class="input" required aria-label="Fecha del saldo">
                <input name="balance" type="number" step="0.01" min="0" class="input" placeholder="Saldo $" required aria-label="Saldo">
                <button class="btn btn-secondary col-span-2">Capturar saldo</button>
            </form>
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @foreach ($debt->balances->reverse() as $capture)
                    <li class="flex items-center justify-between gap-2 py-1.5">
                        <span class="text-slate-500">{{ $capture->balance_on->translatedFormat('d M Y') }}</span>
                        <span class="flex items-center gap-1 tabular-nums">{{ money($capture->balance) }}
                            <form method="POST" action="{{ route('debts.balances.destroy', [$debt, $capture]) }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm" title="Eliminar captura">✕</button>
                            </form>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
        @if ($debt->notes)
            <section class="card text-sm text-slate-600">{{ $debt->notes }}</section>
        @endif
    </div>
</div>
@endsection
