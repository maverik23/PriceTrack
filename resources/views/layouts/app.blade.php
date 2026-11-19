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
    // Menú por secciones: [título, [[ruta, texto, patrón de rutas activas], …]]; null = separador
    $sections = [
        ['Súper', [
            ['lists.index', 'Listas', 'lists.*'],
            ['products.index', 'Artículos', 'products.*'],
            ['providers.index', 'Proveedores', 'providers.*'],
            ['prices.index', 'Precios', 'prices.*'],
            null,
            ['reports.spending', 'Gasto promedio', 'reports.spending'],
            ['reports.inflation', 'Inflación', 'reports.inflation'],
            ['reports.changes', 'Cambios de precio', 'reports.changes'],
        ]],
        ['Cocina', [
            ['recipes.index', 'Recetas', 'recipes.*'],
            ['menu.index', 'Menú de la semana', 'menu.*'],
        ]],
        ['Hogar', [
            ['tasks.index', 'Tareas', 'tasks.*'],
            ['assets.index', 'Bienes', 'assets.*'],
            null,
            ['reports.assets', 'Reporte de bienes', 'reports.assets'],
        ]],
        ['Finanzas', [
            ['investments.index', 'Inversiones', 'investments.*'],
            ['net-worth', 'Patrimonio', 'net-worth'],
        ]],
    ];
    $isActive = fn (array $links) => collect($links)->filter()->contains(fn ($link) => request()->routeIs($link[2]));
    $linkClass = fn (string $pattern) => request()->routeIs($pattern)
        ? 'bg-emerald-50 font-semibold text-emerald-700'
        : 'text-slate-600 hover:bg-slate-100';
@endphp
<header x-data="{ menu: false }" @keydown.escape.window="menu = false"
        class="top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur sm:sticky">
    <div class="mx-auto flex max-w-7xl items-center gap-x-6 gap-y-2 px-4 py-3">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-semibold text-slate-900">
            <span class="grid size-8 place-items-center rounded-lg bg-emerald-600 text-white">$</span>
            PriceTrack
        </a>

        {{-- Escritorio: una entrada por sección con su desplegable --}}
        <nav class="hidden items-center gap-1 text-sm md:flex" aria-label="Principal">
            <a href="{{ route('dashboard') }}" class="rounded-md px-3 py-1.5 {{ $linkClass('dashboard') }}">Inicio</a>
            @foreach ($sections as [$title, $links])
                <div class="relative" x-data="{ open: false }"
                     @keydown.escape.stop="open = false; $el.querySelector('button').focus()"
                     @click.outside="open = false"
                     @focusout="if (! $el.contains($event.relatedTarget)) open = false">
                    <button type="button" @click="open = ! open" :aria-expanded="open" aria-haspopup="true"
                            @keydown.down.prevent="open = true; $nextTick(() => $el.parentElement.querySelector('[role=menu] a')?.focus())"
                            class="inline-flex items-center gap-1 rounded-md px-3 py-1.5 {{ $isActive($links) ? 'bg-emerald-50 font-semibold text-emerald-700' : 'text-slate-600 hover:bg-slate-100' }}">
                        {{ $title }}
                        <svg class="size-4 opacity-60 transition" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M6 8l4 4 4-4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <div x-show="open" x-cloak x-transition.opacity.duration.100ms role="menu"
                         @keydown.down.prevent="const links = Array.from($el.querySelectorAll('a')); links[links.indexOf(document.activeElement) + 1]?.focus()"
                         @keydown.up.prevent="const links = Array.from($el.querySelectorAll('a')); links[Math.max(0, links.indexOf(document.activeElement) - 1)]?.focus()"
                         class="absolute left-0 z-30 mt-1 w-56 rounded-lg bg-white p-1 shadow-lg ring-1 ring-slate-200">
                        @foreach ($links as $link)
                            @if ($link === null)
                                <div class="my-1 h-px bg-slate-100" role="separator"></div>
                            @else
                                <a href="{{ route($link[0]) }}" role="menuitem" class="block rounded-md px-3 py-1.5 {{ $linkClass($link[2]) }}">{{ $link[1] }}</a>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>

        <div class="ml-auto flex items-center gap-2">
            <a href="{{ route('prices.create') }}" class="btn btn-primary btn-sm">+ Capturar precio</a>
            <button type="button" class="btn btn-secondary btn-sm md:hidden" @click="menu = ! menu" :aria-expanded="menu" aria-controls="mobile-nav">
                <span x-text="menu ? 'Cerrar' : 'Menú'">Menú</span>
            </button>
        </div>
    </div>

    {{-- Móvil: todas las secciones a la vista --}}
    <nav id="mobile-nav" x-show="menu" x-cloak class="border-t border-slate-100 px-4 pt-2 pb-4 text-sm md:hidden" aria-label="Principal">
        <a href="{{ route('dashboard') }}" class="block rounded-md px-3 py-2 {{ $linkClass('dashboard') }}">Inicio</a>
        <div class="mt-2 grid grid-cols-1 gap-3 min-[420px]:grid-cols-2">
            @foreach ($sections as [$title, $links])
                <div>
                    <div class="px-3 pb-1 text-xs font-semibold tracking-wide text-slate-400 uppercase">{{ $title }}</div>
                    @foreach (array_filter($links) as $link)
                        <a href="{{ route($link[0]) }}" class="block rounded-md px-3 py-2 {{ $linkClass($link[2]) }}">{{ $link[1] }}</a>
                    @endforeach
                </div>
            @endforeach
        </div>
    </nav>
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

<x-barcode-scanner />

</body>
</html>
