@extends('layouts.app')
@section('title', 'Pagos recurrentes')

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-3xl font-bold text-slate-900">Pagos recurrentes</h1>
        <p class="text-sm text-slate-500">Luz, agua, internet, suscripciones, mensualidades. Registra cada recibo con lo que pagaste (y su lectura si lleva medidor); el siguiente recibo queda pendiente con ese monto de referencia.</p>
    </div>
    <a href="{{ route('debts.index') }}" class="btn btn-secondary">Deudas</a>
</div>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="min-w-0 space-y-6 lg:col-span-2">
        <form method="POST" action="{{ route('recurring.store') }}" class="card space-y-3">
            @csrf
            <h2 class="card-title">Nuevo pago</h2>
            @include('recurring._fields')
            <button class="btn btn-primary">Agregar</button>
        </form>

        <form method="GET" id="recurring-filter" class="flex flex-wrap items-center gap-2" data-live-filter>
            <div class="relative w-full sm:max-w-xs">
                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="q" value="{{ request('q') }}" autocomplete="off" class="input pl-9" placeholder="Buscar pago o categoría…" aria-label="Buscar pago">
            </div>
            <span id="recurring-clear" data-live-part>
                @if (request()->filled('q'))
                    <a href="{{ route('recurring.index') }}" class="btn btn-ghost">Limpiar</a>
                @endif
            </span>
        </form>

        <div id="recurring-results" data-live-results class="space-y-6">
        <section class="card p-0">
            <h2 class="card-title border-b border-slate-100 px-4 py-3">Próximos pagos</h2>
            <ul class="divide-y divide-slate-100">
                @forelse ($items as $item)
                    @include('recurring._row')
                @empty
                    <li class="px-4 py-8 text-center text-sm text-slate-500">
                        {{ request()->filled('q') ? 'Ningún pago coincide con «'.request('q').'».' : 'Sin pagos recurrentes todavía.' }}
                    </li>
                @endforelse
            </ul>
        </section>
        {{ $items->links() }}

        @if ($inactive->isNotEmpty())
            <details class="card p-0">
                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-slate-600">Pausados ({{ $inactive->count() }})</summary>
                <ul class="divide-y divide-slate-100 border-t border-slate-100">
                    @foreach ($inactive as $item)
                        @include('recurring._row')
                    @endforeach
                </ul>
            </details>
        @endif
        </div>
    </div>

    <div class="min-w-0 space-y-6">
        <section class="card">
            <div class="stat-label">Pagas al mes</div>
            <div class="stat-value">{{ money($monthly) }}</div>
            <div class="mt-1 text-xs text-slate-500">{{ money($monthly * 12, 0) }} al año</div>
            @if ($byCategory->count() > 1)
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($byCategory as $category => $amount)
                        <li class="flex justify-between"><span class="text-slate-600">{{ $category }}</span><span class="tabular-nums">{{ money($amount) }}</span></li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="card p-0">
            <h2 class="card-title px-4 pt-4 pb-2">Últimos pagos</h2>
            <ul class="divide-y divide-slate-100 border-t border-slate-100 text-sm">
                @forelse ($recent as $paid)
                    <li class="flex items-center justify-between gap-2 px-4 py-2">
                        <div class="min-w-0">
                            <div class="truncate">{{ $paid->recurringPayment?->name ?? ($paid->debt ? 'Pago a '.$paid->debt->name : 'Pago') }}</div>
                            <div class="text-xs text-slate-500">{{ $paid->paid_on->translatedFormat('d M Y') }}</div>
                        </div>
                        <span class="flex items-center gap-1 tabular-nums">{{ money($paid->amount) }}
                            <form method="POST" action="{{ route('paid.destroy', $paid) }}" onsubmit="return confirm('¿Deshacer este pago?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm" title="Deshacer pago" aria-label="Deshacer pago">✕</button>
                            </form>
                        </span>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-slate-500">Sin pagos todavía.</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
@endsection
