@extends('layouts.app')
@section('title', 'Editar pago')

@section('content')
<a href="{{ route('incomes.index', ['tab' => 'payments']) }}" class="link text-sm">← Ingresos y pagos</a>
<h1 class="mt-2 mb-6 text-3xl font-bold text-slate-900">{{ $payment->name }}</h1>

<form method="POST" action="{{ route('recurring.update', $payment) }}" class="card max-w-3xl space-y-4">
    @csrf @method('PUT')
    @include('recurring._fields')
    <div>
        <label class="label" for="rp-notes">Notas</label>
        <input id="rp-notes" name="notes" value="{{ old('notes', $payment->notes) }}" class="input" maxlength="255">
    </div>
    <label class="flex items-center gap-2 text-sm text-slate-700">
        <input type="hidden" name="active" value="0">
        <input type="checkbox" name="active" value="1" class="size-4 rounded" @checked(old('active', $payment->active))>
        Activo (desmárcalo para pausarlo sin perder su historial)
    </label>
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('incomes.index', ['tab' => 'payments']) }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>

@php
    $history = $payment->history();
    $unit = $payment->meter_unit;
    $chart = collect($history)->reverse()->values();
@endphp
{{-- Historial: cada recibo con su monto y, si lleva medidor, lectura, consumo y precio por unidad (se puede corregir) --}}
<section class="card mt-6 max-w-3xl p-0">
    <div class="flex flex-wrap items-baseline justify-between gap-2 px-5 pt-5 pb-2">
        <h2 class="card-title">Historial</h2>
        @if ($unit)<span class="text-xs text-slate-500">Consumo = lectura − la del recibo anterior</span>@endif
    </div>
    @if ($chart->count() > 1)
        <div class="h-56 px-5 pb-3">
            <canvas data-chart="{{ json_encode(['type' => 'bar', 'format' => 'money', 'labels' => $chart->map(fn ($r) => ($r['payment']->due_on ?? $r['payment']->paid_on)->translatedFormat('M y')), 'datasets' => [
                ['label' => 'Monto', 'data' => $chart->map(fn ($r) => $r['payment']->amount), 'color' => 'ciruela'],
            ]]) }}"></canvas>
        </div>
        @if ($unit && $chart->whereNotNull('unit_price')->count() > 1)
            <div class="h-44 px-5 pb-3">
                <canvas data-chart="{{ json_encode(['type' => 'line', 'format' => 'money', 'labels' => $chart->map(fn ($r) => ($r['payment']->due_on ?? $r['payment']->paid_on)->translatedFormat('M y')), 'datasets' => [
                    ['label' => 'Precio por '.$unit, 'data' => $chart->map(fn ($r) => $r['unit_price']), 'color' => 'sol'],
                ]]) }}"></canvas>
            </div>
        @endif
    @endif
    <ul class="divide-y divide-slate-100 border-t border-slate-100 text-sm">
        @forelse ($history as $row)
            @php $paid = $row['payment']; @endphp
            <li x-data="{ editing: false }" class="px-5 py-2.5">
                <div x-show="!editing" class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span class="min-w-28">
                        {{ $paid->paid_on->translatedFormat('d M Y') }}
                        @if ($paid->due_on && ! $paid->due_on->isSameDay($paid->paid_on))<span class="block text-xs text-slate-500">recibo del {{ $paid->due_on->translatedFormat('d M') }}</span>@endif
                    </span>
                    <span class="font-semibold tabular-nums">{{ money($paid->amount) }}</span>
                    @if ($unit)
                        <span class="text-slate-600 tabular-nums">{{ $paid->reading !== null ? num($paid->reading).' '.$unit : 'sin lectura' }}</span>
                        @if ($row['consumption'] !== null)<span class="text-slate-600 tabular-nums">+{{ num($row['consumption']) }} {{ $unit }}</span>@endif
                        @if ($row['unit_price'] !== null)<span class="font-medium text-slate-800 tabular-nums">{{ money($row['unit_price']) }}/{{ $unit }}</span>@endif
                    @endif
                    @if ($paid->notes)<span class="text-xs text-slate-500">{{ $paid->notes }}</span>@endif
                    <span class="ml-auto flex items-center gap-1">
                        <button type="button" class="btn btn-ghost btn-sm" @click="editing = true">Corregir</button>
                        <form method="POST" action="{{ route('paid.destroy', $paid) }}" onsubmit="return confirm('¿Eliminar este pago?')">
                            @csrf @method('DELETE')
                            <button class="icon-btn size-8 text-slate-400 hover:text-red-600" aria-label="Eliminar pago" title="Eliminar"><x-icon name="trash" class="size-4" /></button>
                        </form>
                    </span>
                </div>
                <form x-show="editing" x-cloak method="POST" action="{{ route('paid.update', $paid) }}" class="grid grid-cols-2 gap-2 sm:grid-cols-5 sm:items-end">
                    @csrf @method('PUT')
                    <label class="text-xs text-slate-500">Fecha<input name="paid_on" type="date" value="{{ $paid->paid_on->toDateString() }}" class="input mt-1 py-1.5 text-sm" required></label>
                    <label class="text-xs text-slate-500">Monto<input name="amount" type="number" step="0.01" min="0.01" value="{{ num($paid->amount) }}" class="input mt-1 py-1.5 text-sm" required></label>
                    @if ($unit)
                        <label class="text-xs text-slate-500">Lectura ({{ $unit }})<input name="reading" type="number" step="0.001" min="0" value="{{ $paid->reading !== null ? num($paid->reading) : '' }}" class="input mt-1 py-1.5 text-sm"></label>
                    @endif
                    <label class="text-xs text-slate-500">Notas<input name="notes" value="{{ $paid->notes }}" class="input mt-1 py-1.5 text-sm" maxlength="255"></label>
                    <div class="col-span-2 flex gap-2 sm:col-span-1">
                        <button class="btn btn-primary btn-sm">Guardar</button>
                        <button type="button" class="btn btn-ghost btn-sm" @click="editing = false">Cancelar</button>
                    </div>
                </form>
            </li>
        @empty
            <li class="px-5 py-6 text-center text-slate-500">Sin pagos todavía.</li>
        @endforelse
    </ul>
</section>

<form method="POST" action="{{ route('recurring.destroy', $payment) }}" class="mt-6" onsubmit="return confirm('¿Eliminar este pago recurrente? Su historial se conserva.')">
    @csrf @method('DELETE')
    <button class="btn btn-danger">Eliminar</button>
</form>
@endsection
