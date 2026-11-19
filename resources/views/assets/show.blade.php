@extends('layouts.app')
@section('title', $asset->name)

@section('content')
@php
    $value = $asset->currentValue();
    $gain = $asset->gain();
    $ownership = $asset->ownershipCost();
    $meter = $asset->currentMeter();
@endphp
<a href="{{ route('assets.index') }}" class="link text-sm">← Bienes</a>
<div class="mt-2 mb-6 flex flex-wrap items-start justify-between gap-3">
    <div class="min-w-0">
        <h1 class="text-2xl font-semibold break-words text-slate-900">{{ $asset->name }}</h1>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
            @if ($asset->category)<span class="badge badge-muted">{{ $asset->category }}</span>@endif
            @if (! $asset->isActive())<span class="badge badge-up">{{ $asset->statusLabel() }} {{ $asset->disposed_on?->translatedFormat('d M Y') }}</span>@endif
            {{ collect([$asset->brand, $asset->model, $asset->location])->filter()->join(' · ') }}
        </p>
    </div>
    <a href="{{ route('assets.edit', $asset) }}" class="btn btn-secondary">Editar</a>
</div>

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="card">
        <div class="stat-label">{{ $asset->isActive() ? 'Valor hoy' : 'Se recibió' }}</div>
        <div class="stat-value">{{ money($asset->isActive() ? $value : $asset->disposal_amount) }}</div>
        <div class="mt-1 text-xs text-slate-500">
            @if ($asset->isActive())
                {{ match ($asset->valuation_method) {
                    'straight_line' => 'Línea recta: '.num($asset->useful_life_years).' años de vida útil',
                    'annual_rate' => num($asset->annual_rate).' % al año',
                    default => 'Última captura o precio de compra',
                } }}
            @endif
        </div>
    </div>
    <div class="card">
        <div class="stat-label">Contra la compra ({{ money($asset->purchase_price) }})</div>
        <div class="stat-value {{ $gain === null ? '' : ($gain >= 0 ? 'text-emerald-700' : 'text-red-600') }}">{{ $gain === null ? '—' : ($gain >= 0 ? '+' : '').money($gain) }}</div>
        <div class="mt-1 text-xs text-slate-500">{{ $gain !== null && $asset->purchase_price > 0 ? pct($gain / $asset->purchase_price) : '' }} {{ $asset->purchased_on ? 'desde el '.$asset->purchased_on->translatedFormat('d M Y') : '' }}</div>
    </div>
    <div class="card">
        <div class="stat-label">Mantenimiento</div>
        <div class="stat-value">{{ money($asset->maintenanceCost()) }}</div>
        <div class="mt-1 text-xs text-slate-500">{{ money($asset->maintenanceCost(today()->year)) }} en {{ today()->year }}</div>
    </div>
    <div class="card">
        <div class="stat-label">Costo de tenerlo</div>
        <div class="stat-value">{{ money($ownership) }}</div>
        <div class="mt-1 text-xs text-slate-500">compra + mantenimiento − {{ $asset->isActive() ? 'valor de hoy' : 'lo recibido' }}</div>
    </div>
</div>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="min-w-0 space-y-6 lg:col-span-2">
        {{-- Mantenimiento --}}
        <section class="card overflow-x-auto p-0">
            <div class="px-5 pt-5 pb-2">
                <h2 class="card-title">Mantenimiento</h2>
                <p class="text-xs text-slate-500">Cada plan deja su recordatorio en <a class="link" href="{{ route('tasks.index') }}">Tareas</a>; al completarlo se registra el servicio.</p>
            </div>
            <table class="table">
                <thead><tr><th>Qué</th><th class="hidden sm:table-cell">Cada</th><th>Próximo</th><th class="text-right">Estimado</th><th></th></tr></thead>
                <tbody>
                @forelse ($asset->maintenancePlans as $plan)
                    <tr>
                        <td class="font-medium text-slate-900">{{ $plan->name }}
                            <div class="text-xs font-normal text-slate-500 sm:hidden">{{ $plan->intervalLabel() }}</div>
                            @if ($last = $plan->lastLog())<div class="text-xs font-normal text-slate-500">Último: {{ $last->done_on->translatedFormat('d M Y') }}</div>@endif
                        </td>
                        <td class="hidden text-sm text-slate-600 sm:table-cell">{{ \Illuminate\Support\Str::after($plan->intervalLabel(), 'Cada ') }}</td>
                        <td class="text-sm">@include('assets._plan-status') <div class="text-xs text-slate-600">{{ $plan->dueLabel() }}</div></td>
                        <td class="text-right tabular-nums">{{ money($plan->estimated_cost) }}</td>
                        <td class="text-right"><a href="{{ route('assets.plans.edit', [$asset, $plan]) }}" class="btn btn-ghost btn-sm">Editar</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-sm text-slate-500">Sin planes. Agrega uno abajo: afinación cada 6 meses o 10,000 km, limpiar el filtro cada mes…</td></tr>
                @endforelse
                </tbody>
            </table>
            <form method="POST" action="{{ route('assets.plans.store', $asset) }}" class="grid grid-cols-1 gap-3 border-t border-slate-100 p-5 sm:grid-cols-6">
                @csrf
                <div class="sm:col-span-6"><h3 class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Nuevo plan</h3></div>
                <div class="sm:col-span-3">
                    <label class="label" for="plan-name">Qué se hace</label>
                    <input id="plan-name" name="name" class="input" maxlength="150" required placeholder="Ej. Afinación">
                </div>
                <div class="sm:col-span-3">
                    <span class="label">Cada</span>
                    <div class="flex gap-2">
                        <input name="every" type="number" min="1" max="999" class="input w-20" aria-label="Cada cuánto tiempo">
                        <select name="every_unit" class="input" aria-label="Unidad de tiempo">
                            <option value="">—</option>
                            @foreach ($units as $value => [$one, $many])
                                <option value="{{ $value }}">{{ $many }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @if ($asset->meter_unit)
                    <div class="sm:col-span-2">
                        <label class="label" for="plan-meter">o cada ({{ $asset->meter_unit }})</label>
                        <input id="plan-meter" name="every_meter" type="number" step="0.1" min="0.1" class="input" placeholder="Ej. 10000">
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <label class="label" for="plan-cost">Costo estimado</label>
                    <input id="plan-cost" name="estimated_cost" type="number" step="0.01" min="0" class="input" placeholder="$">
                </div>
                <div class="flex items-end sm:col-span-2">
                    <button class="btn btn-primary w-full">Agregar plan</button>
                </div>
            </form>
        </section>

        {{-- Servicios hechos --}}
        <section class="card overflow-x-auto p-0">
            <h2 class="card-title px-5 pt-5 pb-2">Servicios hechos</h2>
            <table class="table">
                <thead><tr><th>Fecha</th><th>Qué</th>@if ($asset->meter_unit)<th class="text-right">{{ $asset->meter_unit }}</th>@endif<th class="text-right">Costo</th><th></th></tr></thead>
                <tbody>
                @forelse ($asset->maintenanceLogs as $log)
                    <tr>
                        <td class="whitespace-nowrap text-slate-500">{{ $log->done_on->translatedFormat('d M Y') }}</td>
                        <td>{{ $log->plan?->name ?? 'Otro' }}
                            <div class="text-xs text-slate-500">{{ collect([$log->provider, $log->notes])->filter()->join(' · ') }}</div></td>
                        @if ($asset->meter_unit)<td class="text-right tabular-nums">{{ $log->meter_reading !== null ? number_format($log->meter_reading) : '—' }}</td>@endif
                        <td class="text-right tabular-nums">{{ money($log->cost) }}</td>
                        <td class="text-right">
                            <form method="POST" action="{{ route('assets.logs.destroy', [$asset, $log]) }}" onsubmit="return confirm('¿Eliminar este servicio?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm" title="Eliminar servicio">✕</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-sm text-slate-500">Aún no hay servicios registrados.</td></tr>
                @endforelse
                </tbody>
            </table>
            <form method="POST" action="{{ route('assets.logs.store', $asset) }}" class="grid grid-cols-1 gap-3 border-t border-slate-100 p-5 sm:grid-cols-6">
                @csrf
                <div class="sm:col-span-6"><h3 class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Registrar servicio</h3></div>
                <div class="sm:col-span-2">
                    <label class="label" for="log-plan">Plan</label>
                    <select id="log-plan" name="maintenance_plan_id" class="input">
                        @foreach ($asset->maintenancePlans as $plan)
                            <option value="{{ $plan->id }}" @selected((string) old('maintenance_plan_id') === (string) $plan->id)>{{ $plan->name }}</option>
                        @endforeach
                        <option value="">Otro (reparación…)</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="log-date">Fecha</label>
                    <input id="log-date" name="done_on" type="date" value="{{ old('done_on', today()->toDateString()) }}" max="{{ today()->toDateString() }}" class="input" required>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="log-cost">Costo</label>
                    <input id="log-cost" name="cost" type="number" step="0.01" min="0" value="{{ old('cost') }}" class="input" placeholder="$">
                </div>
                @if ($asset->meter_unit)
                    <div class="sm:col-span-2">
                        <label class="label" for="log-meter">Lectura ({{ $asset->meter_unit }})</label>
                        <input id="log-meter" name="meter_reading" type="number" step="0.1" min="0" value="{{ old('meter_reading') }}" class="input" placeholder="{{ $meter !== null ? number_format($meter, 0, '.', '') : '' }}">
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <label class="label" for="log-provider">Quién lo hizo</label>
                    <input id="log-provider" name="provider" value="{{ old('provider') }}" class="input" maxlength="100">
                </div>
                <div class="{{ $asset->meter_unit ? 'sm:col-span-2' : 'sm:col-span-4' }}">
                    <label class="label" for="log-notes">Notas</label>
                    <input id="log-notes" name="notes" value="{{ old('notes') }}" class="input" maxlength="255">
                </div>
                <div class="sm:col-span-6"><button class="btn btn-primary">Registrar</button></div>
            </form>
        </section>

        <section class="card">
            <h2 class="card-title mb-3">Valor en el tiempo</h2>
            @if (count($series) > 1)
                <div class="h-64">
                    <canvas data-chart="{{ json_encode(['type' => 'line', 'timeAxis' => true, 'format' => 'money', 'beginAtZero' => true, 'datasets' => [['label' => 'Valor', 'data' => $series, 'fill' => true, 'pointRadius' => 0]]]) }}"></canvas>
                </div>
            @else
                <p class="py-10 text-center text-sm text-slate-400">Pon la fecha y el precio de compra, o captura valores, para ver la gráfica.</p>
            @endif
        </section>
    </div>

    <div class="min-w-0 space-y-6">
        @include('attachments._panel', ['owner' => $asset, 'action' => route('assets.attachments.store', $asset), 'hint' => 'Fotos, factura, póliza, garantía, manual…'])

        <section class="card">
            <h2 class="card-title">Capturas de valor</h2>
            <ul class="mt-2 divide-y divide-slate-100 text-sm">
                @if ($asset->purchase_price !== null)
                    <li class="flex justify-between py-1.5 text-slate-500"><span>Compra {{ $asset->purchased_on?->translatedFormat('d M Y') }}</span><span class="tabular-nums">{{ money($asset->purchase_price) }}</span></li>
                @endif
                @foreach ($asset->valuations->reverse() as $valuation)
                    <li class="flex items-center justify-between gap-2 py-1.5">
                        <span>{{ $valuation->valued_on->translatedFormat('d M Y') }} @if ($valuation->notes)<span class="text-xs text-slate-500">· {{ $valuation->notes }}</span>@endif</span>
                        <span class="flex items-center gap-1 tabular-nums">{{ money($valuation->value) }}
                            <form method="POST" action="{{ route('assets.valuations.destroy', [$asset, $valuation]) }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm" title="Eliminar captura" aria-label="Eliminar captura del {{ $valuation->valued_on->translatedFormat('d M Y') }}">✕</button>
                            </form>
                        </span>
                    </li>
                @endforeach
            </ul>
            <form method="POST" action="{{ route('assets.valuations.store', $asset) }}" class="mt-3 grid grid-cols-2 gap-2">
                @csrf
                <input name="valued_on" type="date" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" class="input" required aria-label="Fecha del valor">
                <input name="value" type="number" step="0.01" min="0" class="input" placeholder="Valor $" required aria-label="Valor">
                <input name="notes" class="input col-span-2" maxlength="255" placeholder="De dónde: avalúo, Libro Azul…" aria-label="Notas del valor">
                <button class="btn btn-secondary col-span-2">Capturar valor</button>
            </form>
        </section>

        @if ($asset->meter_unit)
            <section class="card">
                <h2 class="card-title">Medidor</h2>
                <div class="stat-value">{{ $meter !== null ? number_format($meter).' '.$asset->meter_unit : '—' }}</div>
                <ul class="mt-2 divide-y divide-slate-100 text-sm">
                    @foreach ($asset->meterReadings->reverse()->take(6) as $reading)
                        <li class="flex items-center justify-between py-1.5">
                            <span class="text-slate-500">{{ $reading->read_on->translatedFormat('d M Y') }}</span>
                            <span class="flex items-center gap-1 tabular-nums">{{ number_format($reading->reading) }}
                                <form method="POST" action="{{ route('assets.readings.destroy', [$asset, $reading]) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-danger btn-sm" title="Eliminar lectura">✕</button>
                                </form>
                            </span>
                        </li>
                    @endforeach
                </ul>
                <form method="POST" action="{{ route('assets.readings.store', $asset) }}" class="mt-3 grid grid-cols-2 gap-2">
                    @csrf
                    <input name="read_on" type="date" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" class="input" required aria-label="Fecha de la lectura">
                    <input name="reading" type="number" step="0.1" min="0" class="input" placeholder="{{ $asset->meter_unit }}" required aria-label="Lectura">
                    <button class="btn btn-secondary col-span-2">Registrar lectura</button>
                </form>
            </section>
        @endif

        @if ($tasks->isNotEmpty())
            <section class="card p-0">
                <h2 class="card-title px-4 pt-4 pb-2">Recordatorios pendientes</h2>
                <ul class="divide-y divide-slate-100 border-t border-slate-100">
                    @foreach ($tasks as $task)
                        @include('tasks._row', ['task' => $task])
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="card text-sm">
            <h2 class="card-title mb-2">Detalles</h2>
            <dl class="grid grid-cols-2 gap-x-3 gap-y-1.5">
                @foreach (['Número de serie' => $asset->serial_number, 'Compra' => $asset->purchased_on?->translatedFormat('d M Y'), 'Garantía' => $asset->warranty_until ? ($asset->warrantyActive() ? 'Hasta ' : 'Venció ').$asset->warranty_until->translatedFormat('d M Y') : null, 'Ubicación' => $asset->location] as $label => $text)
                    @if ($text)
                        <dt class="text-slate-500">{{ $label }}</dt><dd class="break-words text-slate-800">{{ $text }}</dd>
                    @endif
                @endforeach
            </dl>
            @if ($asset->notes)<p class="mt-3 whitespace-pre-line text-slate-600">{{ $asset->notes }}</p>@endif
        </section>
    </div>
</div>
@endsection
