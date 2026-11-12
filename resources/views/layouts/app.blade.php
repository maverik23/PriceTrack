<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') · @endif PriceTrack</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-800 antialiased">
@php
    $nav = [
        ['dashboard', 'Inicio', 'dashboard'],
        ['lists.index', 'Listas', 'lists.*'],
        ['products.index', 'Artículos', 'products.*'],
        ['providers.index', 'Proveedores', 'providers.*'],
        ['prices.index', 'Precios', 'prices.*'],
    ];
    $reports = [
        ['reports.spending', 'Gasto promedio'],
        ['reports.inflation', 'Inflación'],
        ['reports.changes', 'Cambios de precio'],
    ];
@endphp
<header class="top-0 z-20 sm:sticky border-b border-slate-200 bg-white/90 backdrop-blur">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-semibold text-slate-900">
            <span class="grid size-8 place-items-center rounded-lg bg-emerald-600 text-white">$</span>
            PriceTrack
        </a>
        <nav class="flex flex-wrap items-center gap-1 text-sm">
            @foreach ($nav as [$route, $label, $pattern])
                <a href="{{ route($route) }}"
                   class="rounded-md px-3 py-1.5 {{ request()->routeIs($pattern) ? 'bg-emerald-50 font-semibold text-emerald-700' : 'text-slate-600 hover:bg-slate-100' }}">{{ $label }}</a>
            @endforeach
            <span class="mx-1 h-5 w-px bg-slate-200"></span>
            @foreach ($reports as [$route, $label])
                <a href="{{ route($route) }}"
                   class="rounded-md px-3 py-1.5 {{ request()->routeIs($route) ? 'bg-emerald-50 font-semibold text-emerald-700' : 'text-slate-600 hover:bg-slate-100' }}">{{ $label }}</a>
            @endforeach
        </nav>
        <a href="{{ route('prices.create') }}" class="btn btn-primary btn-sm ml-auto">+ Capturar precio</a>
    </div>
</header>

<main class="mx-auto max-w-7xl px-4 py-6">
    @if (session('success'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-emerald-200">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200">
            <p class="font-semibold">Revisa los datos:</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

<footer class="mx-auto max-w-7xl px-4 pb-8 text-xs text-slate-400">
    Precios por unidad normalizados a $/L, $/kg y $/pza · Fechas en {{ config('app.timezone') }}
</footer>
</body>
</html>
