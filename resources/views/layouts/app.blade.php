<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f7f4ec">
    <title>@hasSection('title')@yield('title') · @endif PriceTrack</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    {{-- Tema antes de pintar, para que no parpadee: auto (el del sistema), claro u oscuro --}}
    <script>
        (function () {
            var choice = 'auto';
            try { choice = localStorage.getItem('theme') || 'auto'; } catch (e) {}
            var dark = choice === 'dark' || (choice === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    // Navegación por secciones: clave => [título, icono, [[ruta, texto, patrón de rutas activas, icono], …]]
    $sections = [
        'super' => ['Súper', 'shopping-basket', [
            ['lists.index', 'Listas de compra', 'lists.*', 'shopping-cart'],
            ['products.index', 'Artículos', 'products.*', 'package'],
            ['providers.index', 'Proveedores', 'providers.*', 'store'],
            ['prices.index', 'Precios', 'prices.*', 'tags'],
            ['reports.spending', 'Gasto promedio', 'reports.spending', 'receipt-text'],
            ['reports.inflation', 'Inflación', 'reports.inflation', 'trending-up'],
            ['reports.changes', 'Cambios de precio', 'reports.changes', 'arrow-left-right'],
        ]],
        'cocina' => ['Cocina', 'chef-hat', [
            ['recipes.index', 'Recetas', 'recipes.*', 'book-open'],
            ['menu.index', 'Menú de la semana', 'menu.*', 'calendar-days'],
        ]],
        'hogar' => ['Hogar', 'house', [
            ['tasks.index', 'Tareas', 'tasks.*', 'list-checks'],
            ['assets.index', 'Bienes', 'assets.*', 'sofa'],
            ['reports.assets', 'Reporte de bienes', 'reports.assets', 'wrench'],
        ]],
        'finanzas' => ['Finanzas', 'wallet', [
            ['net-worth', 'Patrimonio', 'net-worth', 'landmark'],
            ['investments.index', 'Inversiones', 'investments.*', 'chart-line'],
            ['debts.index', 'Deudas', 'debts.*', 'credit-card'],
            ['recurring.index', 'Pagos recurrentes', 'recurring.*', 'repeat'],
        ]],
    ];
    $current = collect($sections)->search(fn ($section) => collect($section[2])->contains(fn ($link) => request()->routeIs($link[2]))) ?: 'inicio';
@endphp
<body data-section="{{ $current }}" class="min-h-screen bg-slate-50 font-sans text-slate-800 antialiased"
      x-data="{ sheet: null }" @keydown.escape.window="sheet = null">

{{-- Escritorio: barra lateral con todo a la vista --}}
<aside class="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-r border-slate-200/80 bg-surface/80 backdrop-blur lg:flex">
    <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 px-5 pt-5 pb-4">
        <x-logo class="size-9" />
        <span class="font-display text-xl font-bold tracking-tight text-slate-900">PriceTrack</span>
    </a>
    <div class="px-4 pb-3">
        <a href="{{ route('prices.create') }}" class="btn btn-primary w-full"><x-icon name="plus" class="size-4" /> Capturar precio</a>
    </div>
    <nav class="flex-1 overflow-y-auto px-3 pb-4" aria-label="Principal">
        <a href="{{ route('dashboard') }}" data-nav="inicio" @class(['nav-link', 'is-active' => $current === 'inicio'])
           @if ($current === 'inicio') aria-current="page" @endif>
            <x-icon name="sun" class="size-[18px]" /> Inicio
        </a>
        @foreach ($sections as $key => [$title, $icon, $links])
            <div class="nav-heading" data-nav="{{ $key }}"><span class="nav-dot"></span>{{ $title }}</div>
            @foreach ($links as [$route, $label, $pattern, $linkIcon])
                <a href="{{ route($route) }}" data-nav="{{ $key }}" @class(['nav-link', 'is-active' => request()->routeIs($pattern)])
                   @if (request()->routeIs($pattern)) aria-current="page" @endif>
                    <x-icon :name="$linkIcon" class="size-[18px]" /> {{ $label }}
                </a>
            @endforeach
        @endforeach
    </nav>
    <div class="flex items-center justify-between gap-2 border-t border-slate-200/80 px-4 py-3">
        <span class="text-xs text-slate-400">Tu casa, en orden</span>
        <x-theme-toggle />
    </div>
</aside>

{{-- Móvil: barra superior --}}
<header class="sticky top-0 z-30 flex items-center gap-3 border-b border-slate-200/80 bg-surface/85 px-4 py-2.5 backdrop-blur lg:hidden">
    <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
        <x-logo class="size-8" />
        <span class="font-display text-lg font-bold tracking-tight text-slate-900">PriceTrack</span>
    </a>
    @if ($current !== 'inicio')
        <span class="section-chip">{{ $sections[$current][0] }}</span>
    @endif
    <div class="ml-auto flex items-center gap-1.5">
        <x-theme-toggle />
        <a href="{{ route('prices.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="size-3.5" /> Precio</a>
    </div>
</header>

<div class="lg:pl-64">
    <main class="mx-auto max-w-[1320px] px-4 pt-5 pb-28 sm:px-6 lg:px-8 lg:pt-8 lg:pb-10">
        @if (session('success'))
            <div class="flash flash-success" role="status"><x-icon name="check" class="mt-0.5 size-4" /><div>{{ session('success') }}</div></div>
        @endif
        @if ($errors->any())
            <div class="flash flash-error" role="alert">
                <div>
                    <p class="font-semibold">Revisa los datos:</p>
                    <ul class="mt-1 list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="mx-auto hidden max-w-[1320px] px-8 pb-8 text-xs text-slate-400 lg:block">
        Precios por unidad en $/L, $/kg y $/pza · Montos en pesos · Fechas en {{ config('app.timezone') }}
    </footer>
</div>

{{-- Móvil: pestañas abajo; cada sección abre su hoja con sus pantallas --}}
<div class="lg:hidden">
    <div x-show="sheet" x-cloak x-transition.opacity class="fixed inset-0 z-30 bg-black/40" @click="sheet = null" aria-hidden="true"></div>
    @foreach ($sections as $key => [$title, $icon, $links])
        <div x-show="sheet === '{{ $key }}'" x-cloak data-nav="{{ $key }}" id="sheet-{{ $key }}"
             x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-6 opacity-0"
             x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="translate-y-6 opacity-0"
             class="tab-sheet" role="dialog" aria-label="{{ $title }}">
            <div class="mb-2 flex items-center gap-2 px-2">
                <span class="nav-dot"></span>
                <span class="font-display text-lg font-semibold text-slate-900">{{ $title }}</span>
            </div>
            <div class="grid grid-cols-2 gap-2">
                @foreach ($links as [$route, $label, $pattern, $linkIcon])
                    <a href="{{ route($route) }}" @class(['sheet-link', 'is-active' => request()->routeIs($pattern)])>
                        <x-icon :name="$linkIcon" class="size-5" /> <span>{{ $label }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach

    <nav class="tab-bar" aria-label="Principal">
        <a href="{{ route('dashboard') }}" data-nav="inicio" @class(['tab', 'is-active' => $current === 'inicio'])>
            <x-icon name="sun" class="size-[22px]" /><span>Inicio</span>
        </a>
        @foreach ($sections as $key => [$title, $icon, $links])
            <button type="button" data-nav="{{ $key }}" @class(['tab', 'is-active' => $current === $key])
                    :class="sheet === '{{ $key }}' && 'is-open'"
                    @click="sheet = sheet === '{{ $key }}' ? null : '{{ $key }}'"
                    :aria-expanded="sheet === '{{ $key }}'" aria-controls="sheet-{{ $key }}">
                <x-icon :name="$icon" class="size-[22px]" /><span>{{ $title }}</span>
            </button>
        @endforeach
    </nav>
</div>

<x-barcode-scanner />

</body>
</html>
