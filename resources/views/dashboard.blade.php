@extends('layouts.app')

@section('content')
@php
    $hour = now()->hour;
    $greeting = $hour < 12 ? 'Buenos días' : ($hour < 19 ? 'Buenas tardes' : 'Buenas noches');
    // Una frase con lo de hoy: tareas, pagos y qué se come
    $today = collect([
        $dueTasks->count() ? $dueTasks->count().' '.($dueTasks->count() === 1 ? 'tarea' : 'tareas') : null,
        $duePayments->count() ? $duePayments->count().' '.($duePayments->count() === 1 ? 'pago' : 'pagos').' esta semana' : null,
        $todayMeals->count() ? $todayMeals->count().' '.($todayMeals->count() === 1 ? 'comida planeada' : 'comidas planeadas') : null,
    ])->filter()->values();
@endphp
<div class="mb-7 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="eyebrow">{{ today()->translatedFormat('l d \d\e F') }}</p>
        <h1 class="mt-1 text-4xl font-bold text-slate-900 sm:text-[2.75rem] sm:leading-[1.05]">{{ $greeting }}</h1>
        <p class="mt-2 text-sm text-slate-500">
            @if ($today->isEmpty())
                Hoy no tienes nada pendiente.
            @else
                Para hoy: {{ $today->join(', ', ' y ') }}.
            @endif
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('lists.create') }}" class="btn btn-primary"><x-icon name="shopping-cart" class="size-4" /> Nueva lista</a>
        <a href="{{ route('tasks.index') }}" class="btn btn-secondary"><x-icon name="plus" class="size-4" /> Tarea</a>
        <a href="{{ route('menu.index') }}" class="btn btn-secondary"><x-icon name="calendar-days" class="size-4" /> Menú semanal</a>
    </div>
</div>

@if ($counts['products'] === 0 && ! $hasFinance)
    <div class="card mb-6">
        <h2 class="card-title">Empieza por donde quieras</h2>
        <p class="mt-1 text-sm text-slate-500">Cada sección funciona por su cuenta; llena la que te sirva primero.</p>
        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="flex gap-3" data-nav="super">
                <span class="icon-tile"><x-icon name="shopping-basket" class="size-5" /></span>
                <p class="text-sm text-slate-600"><strong class="text-slate-900">Súper.</strong> Revisa tus <a class="link" href="{{ route('providers.index') }}">proveedores</a>, da de alta tus <a class="link" href="{{ route('products.create') }}">artículos</a> y crea una <a class="link" href="{{ route('lists.create') }}">lista de compra</a>.</p>
            </div>
            <div class="flex gap-3" data-nav="cocina">
                <span class="icon-tile"><x-icon name="chef-hat" class="size-5" /></span>
                <p class="text-sm text-slate-600"><strong class="text-slate-900">Cocina.</strong> Guarda tus <a class="link" href="{{ route('recipes.create') }}">recetas</a> y arma el <a class="link" href="{{ route('menu.index') }}">menú de la semana</a>.</p>
            </div>
            <div class="flex gap-3" data-nav="hogar">
                <span class="icon-tile"><x-icon name="house" class="size-5" /></span>
                <p class="text-sm text-slate-600"><strong class="text-slate-900">Hogar.</strong> Anota tus <a class="link" href="{{ route('tasks.index') }}">tareas</a> y tus <a class="link" href="{{ route('assets.create') }}">bienes</a> con su mantenimiento.</p>
            </div>
            <div class="flex gap-3" data-nav="finanzas">
                <span class="icon-tile"><x-icon name="wallet" class="size-5" /></span>
                <p class="text-sm text-slate-600"><strong class="text-slate-900">Finanzas.</strong> Registra tus <a class="link" href="{{ route('investments.create') }}">inversiones</a>, <a class="link" href="{{ route('debts.create') }}">deudas</a> y <a class="link" href="{{ route('recurring.index') }}">pagos recurrentes</a> para ver tu patrimonio y lo que toca pagar.</p>
            </div>
        </div>
    </div>
@endif

{{-- Lo más importante de cada sección, con su color --}}
<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <a href="{{ route('reports.spending') }}" class="card stat-card" data-nav="super">
        <div class="flex items-start justify-between gap-2">
            <div class="eyebrow">Súper</div>
            <span class="icon-tile"><x-icon name="shopping-basket" class="size-5" /></span>
        </div>
        <div class="stat-label">Gasto este mes</div>
        <div class="stat-value">{{ money($thisMonth['total']) }}</div>
        <div class="mt-1 text-xs text-slate-500">Mes pasado: {{ money($lastMonth['total']) }} · Promedio: {{ money($monthlyAvg) }}</div>
    </a>
    <a href="{{ route('menu.index') }}" class="card stat-card" data-nav="cocina">
        <div class="flex items-start justify-between gap-2">
            <div class="eyebrow">Cocina</div>
            <span class="icon-tile"><x-icon name="chef-hat" class="size-5" /></span>
        </div>
        <div class="stat-label">Costo de la semana</div>
        <div class="stat-value">{{ money($weekCost) }}</div>
        <div class="mt-1 text-xs text-slate-500">{{ $weekMeals }} {{ $weekMeals === 1 ? 'comida planeada' : 'comidas planeadas' }}</div>
    </a>
    <a href="{{ route('tasks.index') }}" class="card stat-card" data-nav="hogar">
        <div class="flex items-start justify-between gap-2">
            <div class="eyebrow">Hogar</div>
            <span class="icon-tile"><x-icon name="list-checks" class="size-5" /></span>
        </div>
        <div class="stat-label">Tareas para hoy</div>
        <div class="stat-value">{{ $dueTasks->count() }}
            @if ($overdueCount)<span class="font-sans text-sm font-semibold tracking-normal text-red-600">{{ $overdueCount }} {{ $overdueCount === 1 ? 'vencida' : 'vencidas' }}</span>@endif
        </div>
        <div class="mt-1 text-xs text-slate-500">{{ $upcomingTasks }} en los próximos 7 días</div>
    </a>
    <a href="{{ route('net-worth') }}" class="card stat-card" data-nav="finanzas">
        <div class="flex items-start justify-between gap-2">
            <div class="eyebrow">Finanzas</div>
            <span class="icon-tile"><x-icon name="wallet" class="size-5" /></span>
        </div>
        <div class="stat-label">Patrimonio neto</div>
        <div class="stat-value">{{ money($netWorth['total'], 0) }}</div>
        <div class="mt-1 text-xs text-slate-500">
            @if ($netWorth['debts'] > 0)<span class="text-red-600">Deudas −{{ money($netWorth['debts'], 0) }}</span> · @endif
            @if ($netWorthChange !== null)
                <span class="{{ $netWorthDelta >= 0 ? 'text-emerald-700' : 'text-red-600' }}">{{ $netWorthDelta >= 0 ? '+' : '' }}{{ money($netWorthDelta, 0) }} ({{ pct($netWorthChange) }})</span> en el mes
            @else
                Inversiones + bienes
            @endif
        </div>
    </a>
</div>

<div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
    {{-- Hogar: tareas de hoy y vencidas --}}
    <section class="card p-0">
        <div class="flex items-center justify-between px-4 pt-4 pb-2">
            <h2 class="card-title flex items-center gap-2" data-nav="hogar"><span class="nav-dot"></span>Tareas de hoy y vencidas</h2>
            <a href="{{ route('tasks.index') }}" class="link text-xs">Ver todas</a>
        </div>
        @if ($dueTasks->isEmpty())
            <p class="px-4 pb-5 text-sm text-slate-500">Nada pendiente para hoy.</p>
        @else
            <ul class="divide-y divide-slate-100 border-t border-slate-100">
                @foreach ($dueTasks->take(6) as $task)
                    @include('tasks._row', ['showList' => true])
                @endforeach
            </ul>
            @if ($dueTasks->count() > 6)
                <a href="{{ route('tasks.index') }}" class="block border-t border-slate-100 px-4 py-2 text-xs link">y {{ $dueTasks->count() - 6 }} más…</a>
            @endif
        @endif
    </section>

    {{-- Finanzas: pagos por hacer --}}
    <section class="card p-0">
        <div class="flex items-center justify-between px-4 pt-4 pb-2">
            <h2 class="card-title flex items-center gap-2" data-nav="finanzas"><span class="nav-dot"></span>Pagos por hacer ({{ \App\Models\RecurringPayment::SOON_DAYS }} días)</h2>
            <a href="{{ route('recurring.index') }}" class="link text-xs">Pagos recurrentes</a>
        </div>
        @if ($duePayments->isEmpty())
            <p class="px-4 pb-5 text-sm text-slate-500">
                @if ($hasRecurring)
                    Nada por pagar esta semana.
                @else
                    Anota tus <a class="link" href="{{ route('recurring.index') }}">pagos recurrentes</a> (luz, internet, mensualidades) para verlos aquí.
                @endif
            </p>
        @else
            <ul class="divide-y divide-slate-100 border-t border-slate-100">
                @foreach ($duePayments as $item)
                    @include('recurring._row')
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Cocina --}}
    <section class="card">
        <div class="flex items-center justify-between">
            <h2 class="card-title flex items-center gap-2" data-nav="cocina"><span class="nav-dot"></span>Hoy se come</h2>
            <a href="{{ route('menu.index') }}" class="link text-xs">Menú de la semana</a>
        </div>
        @if ($todayMeals->isEmpty())
            <p class="mt-2 text-sm text-slate-500">No hay nada planeado para hoy. <a class="link" href="{{ route('menu.index') }}">Arma el menú</a>.</p>
        @else
            <ul class="mt-2 space-y-2 text-sm">
                @foreach ($todayMeals as $entry)
                    <li class="rounded-xl bg-slate-50 px-3 py-2.5 ring-1 ring-slate-200">
                        <div class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ $entry->mealLabel() }}</div>
                        @if ($entry->recipe)
                            <a href="{{ route('recipes.show', ['recipe' => $entry->recipe, 'servings' => $entry->plannedServings()]) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $entry->recipe->name }}</a>
                            <span class="text-xs text-slate-500">· {{ $entry->plannedServings() }} porc.</span>
                        @else
                            <span class="text-slate-700">{{ $entry->title }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Hogar: bienes --}}
    <section class="card">
        <div class="flex items-center justify-between">
            <h2 class="card-title flex items-center gap-2" data-nav="hogar"><span class="nav-dot"></span>Mantenimiento y garantías</h2>
            <a href="{{ route('reports.assets') }}" class="link text-xs">Reporte</a>
        </div>
        @php($plans = $assetSummary['overdue']->merge($assetSummary['soon']))
        @if (! $hasAssets)
            <p class="mt-2 text-sm text-slate-500">Agrega tus <a class="link" href="{{ route('assets.create') }}">bienes</a> para llevar su mantenimiento, garantías y valor.</p>
        @elseif ($plans->isEmpty() && $assetSummary['warranties']->isEmpty())
            <p class="mt-2 text-sm text-slate-500">Todo al día: ningún servicio ni garantía en los próximos días.</p>
        @else
            <ul class="mt-2 divide-y divide-slate-100 text-sm">
                @foreach ($plans->take(5) as $plan)
                    <li class="flex items-start justify-between gap-2 py-2">
                        <div class="min-w-0">
                            <a href="{{ route('assets.show', $plan->asset) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $plan->name }}</a>
                            <div class="text-xs text-slate-500">{{ $plan->asset->name }} · {{ $plan->dueLabel() }}</div>
                        </div>
                        @include('assets._plan-status')
                    </li>
                @endforeach
                @foreach ($assetSummary['warranties'] as $asset)
                    <li class="flex items-start justify-between gap-2 py-2">
                        <div class="min-w-0">
                            <a href="{{ route('assets.show', $asset) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $asset->name }}</a>
                            <div class="text-xs text-slate-500">Garantía hasta el {{ $asset->warranty_until->translatedFormat('d M Y') }}</div>
                        </div>
                        <span class="badge bg-amber-50 text-amber-800">Garantía</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>

{{-- Súper --}}
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <a href="{{ route('reports.inflation') }}" class="card stat-card" data-nav="super">
        <div class="flex items-start justify-between gap-2">
            <div class="eyebrow">Súper</div>
            <span class="icon-tile"><x-icon name="trending-up" class="size-5" /></span>
        </div>
        <div class="stat-label">Inflación de tu canasta (12 meses)</div>
        <div class="stat-value {{ ($inflation['overall'] ?? 0) > 0 ? 'text-red-600' : 'text-emerald-700' }}">{{ pct($inflation['overall']) }}</div>
        <div class="mt-1 text-xs text-slate-500">{{ $inflation['up'] }} artículos subieron · {{ $inflation['down'] }} bajaron</div>
        <div class="mt-4 text-xs text-slate-500">{{ $counts['products'] }} artículos · {{ $counts['providers'] }} proveedores · {{ number_format($counts['prices']) }} precios en {{ $counts['lists'] }} listas</div>
    </a>

    <div class="card overflow-x-auto p-0">
        <div class="flex items-center justify-between px-5 pt-5 pb-2">
            <h2 class="card-title flex items-center gap-2" data-nav="super"><span class="nav-dot"></span>Últimas listas</h2>
            <a href="{{ route('lists.index') }}" class="link text-xs">Ver todas</a>
        </div>
        <table class="table">
            <tbody>
            @forelse ($lists as $list)
                <tr>
                    <td class="whitespace-nowrap text-slate-500">{{ $list->shopped_on->translatedFormat('d M') }}</td>
                    <td><a class="font-medium hover:text-emerald-700" href="{{ route('lists.show', $list) }}">{{ $list->name }}</a>
                        <div class="text-xs text-slate-500">{{ $list->purchasedCount() }}/{{ $list->items->count() }} comprados</div></td>
                    <td class="text-right font-semibold tabular-nums">{{ money($list->total()) }}</td>
                </tr>
            @empty
                <tr><td class="py-8 text-center text-slate-500">Sin listas todavía.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="card overflow-x-auto p-0">
        <div class="flex items-center justify-between px-5 pt-5 pb-2">
            <h2 class="card-title flex items-center gap-2" data-nav="super"><span class="nav-dot"></span>Cambios de precio (60 días)</h2>
            <a href="{{ route('reports.changes') }}" class="link text-xs">Ver todos</a>
        </div>
        <table class="table">
            <tbody>
            @forelse ($changes as $c)
                <tr>
                    <td><a class="font-medium hover:text-emerald-700" href="{{ route('products.show', $c->product) }}">{{ $c->product->name }}</a>
                        <div class="text-xs text-slate-500">{{ $c->provider->name }} · {{ $c->current->observed_on->translatedFormat('d M') }}</div></td>
                    <td class="text-right"><span class="badge {{ $c->change > 0 ? 'badge-up' : 'badge-down' }}">{{ pct($c->change) }}</span></td>
                </tr>
            @empty
                <tr><td class="py-8 text-center text-slate-500">Sin cambios recientes.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
