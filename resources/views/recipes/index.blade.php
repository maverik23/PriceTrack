@extends('layouts.app')
@section('title', 'Recetas')

@section('content')
@php
    $filtered = request()->anyFilled(['q', 'category', 'tag', 'rating', 'pantry']);
    $keep = request()->only(['q', 'category', 'rating', 'tag']);
@endphp
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-3xl font-bold text-slate-900">Recetas</h1>
        <p class="text-sm text-slate-500">El costo sale de lo que usa cada receta, con el precio más barato de cada artículo.</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('menu.index') }}" class="btn btn-secondary">Menú de la semana</a>
        <a href="{{ route('recipes.create') }}" class="btn btn-primary">+ Nueva receta</a>
    </div>
</div>

<form method="GET" id="recipes-filter" class="mb-3 flex flex-wrap gap-2" data-live-filter data-live-links>
    <div class="relative w-full sm:max-w-xs">
        <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
        <input type="search" name="q" value="{{ request('q') }}" autocomplete="off" class="input pl-9" placeholder="Buscar receta…" aria-label="Buscar receta">
    </div>
    {{-- Siempre presentes (vacíos = sin filtro) para que el filtro en vivo los conserve al cambiar de etiqueta --}}
    <input type="hidden" name="tag" value="{{ request('tag') }}">
    <input type="hidden" name="pantry" value="{{ $pantry }}">
    <select name="category" class="input w-auto max-w-52" aria-label="Categoría">
        <option value="">Todas las categorías</option>
        @foreach ($categories as $category)
            <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
        @endforeach
    </select>
    <select name="rating" class="input w-auto" aria-label="Calificación">
        <option value="">Cualquier calificación</option>
        @foreach ([5 => '★★★★★', 4 => '★★★★ o más', 3 => '★★★ o más'] as $value => $label)
            <option value="{{ $value }}" @selected((int) request('rating') === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <span id="recipes-clear" data-live-part>
        @if ($filtered)
            <a href="{{ route('recipes.index') }}" class="btn btn-ghost">Limpiar</a>
        @endif
    </span>
</form>
<div id="recipes-results" data-live-results>

{{-- Con lo que hay en la despensa (Recipe::pantryCheck()) --}}
<div class="mb-3 flex flex-wrap items-center gap-2" aria-label="Despensa">
    <a href="{{ route('recipes.index', $keep) }}" @class(['chip', 'is-active' => ! $pantry])>Todas</a>
    <a href="{{ route('recipes.index', [...$keep, 'pantry' => 1]) }}" @class(['chip', 'is-active' => $pantry === '1'])>
        <x-icon name="refrigerator" class="size-4" /> Con lo que hay <span class="opacity-70">{{ $readyCount }}</span>
    </a>
    <a href="{{ route('recipes.index', [...$keep, 'pantry' => 'almost']) }}" @class(['chip', 'is-active' => $pantry === 'almost'])>
        Falta poco <span class="opacity-70">{{ $almostCount }}</span>
    </a>
    @if ($pantry)
        <span class="text-xs text-slate-500">Según tu <a class="link" href="{{ route('pantry.index') }}">despensa</a>; la sal, el agua y lo escrito sin artículo no se revisan.</span>
    @endif
</div>

@if ($tags->isNotEmpty())
    <div class="mb-5 flex flex-wrap gap-2" aria-label="Etiquetas">
        @foreach ($tags as $name)
            @php
                $active = request('tag') === $name;
            @endphp
            <a href="{{ route('recipes.index', array_filter([...request()->only(['q', 'category', 'rating', 'pantry']), 'tag' => $active ? null : $name])) }}"
               @class(['chip', 'is-active' => $active]) @if ($active) aria-current="true" @endif>{{ $name }}</a>
        @endforeach
    </div>
@endif

@if ($recipes->isEmpty())
    <div class="card py-12 text-center text-slate-500">
        @if ($pantry === '1')
            Con lo que hay en tu despensa no alcanza para ninguna receta todavía. <a class="link" href="{{ route('recipes.index', [...$keep, 'pantry' => 'almost']) }}">Ver a las que les falta poco</a>
            o marca en la <a class="link" href="{{ route('pantry.index') }}">despensa</a> lo que tienes.
        @elseif ($filtered)
            Ninguna receta coincide. <a class="link" href="{{ route('recipes.index') }}">Ver todas</a>.
        @else
            No hay recetas todavía. <a class="link" href="{{ route('recipes.create') }}">Agrega la primera</a>.
        @endif
    </div>
@else
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($recipes as $recipe)
            @php
                $cover = $recipe->coverPhoto();
                $perServing = $recipe->costPerServing();
                $nutrition = $recipe->nutrition();
                $check = $checks[$recipe->id] ?? null;
            @endphp
            <a href="{{ route('recipes.show', $recipe) }}" class="card group flex flex-col overflow-hidden p-0">
                <div @class(['relative bg-slate-100', 'aspect-[16/10]' => $cover, 'h-14 sm:aspect-[16/10] sm:h-auto' => ! $cover])>
                    @if ($cover)
                        <img src="{{ $cover->url(true) }}" alt="" loading="lazy" class="size-full object-cover">
                    @else
                        <div class="grid size-full place-items-center text-slate-300" data-nav="cocina"><x-icon name="chef-hat" class="hidden size-12 sm:block" /></div>
                    @endif
                    @if ($recipe->category)
                        <span class="badge absolute top-2 left-2 bg-surface/90 text-slate-700 shadow-sm">{{ $recipe->category }}</span>
                    @endif
                </div>
                <div class="flex flex-1 flex-col gap-2 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="font-display text-lg leading-tight font-semibold text-slate-900 group-hover:text-emerald-700">{{ $recipe->name }}</h2>
                        @if ($recipe->rating)
                            <span class="flex shrink-0 items-center gap-0.5 text-amber-500" title="{{ $recipe->rating }} de 5" aria-label="{{ $recipe->rating }} de 5">
                                @for ($i = 1; $i <= 5; $i++)
                                    <x-icon name="star" :class="'size-3.5'.($i > $recipe->rating ? ' opacity-30' : '')" :fill="$i <= $recipe->rating ? 'currentColor' : 'none'" />
                                @endfor
                            </span>
                        @endif
                    </div>
                    @if ($check && $check['ready'])
                        <span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-700"><x-icon name="refrigerator" class="size-3.5" /> Tienes todo en casa</span>
                    @elseif ($check && $pantry && count($check['missing']) <= 2)
                        <span class="text-xs text-amber-700">Falta: {{ implode(', ', $check['missing']) }}</span>
                    @endif
                    @if ($recipe->tags->isNotEmpty())
                        <div class="flex flex-wrap gap-1">
                            @foreach ($recipe->tags as $tag)<span class="badge badge-cheap">{{ $tag->name }}</span>@endforeach
                        </div>
                    @endif
                    <div class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 pt-1 text-xs text-slate-500">
                        <span>{{ $recipe->servings }} porc.</span>
                        @if ($recipe->minutes)<span class="inline-flex items-center gap-1"><x-icon name="timer" class="size-3.5" />{{ $recipe->minutes }} min</span>@endif
                        @if ($nutrition)<span>{{ number_format($nutrition['perServing']['kcal']) }} kcal/porc.</span>@endif
                        <span class="ml-auto text-sm font-semibold text-slate-900 tabular-nums" title="Costo por porción">{{ $perServing !== null ? money($perServing).' /porc.' : 'Sin costo' }}</span>
                    </div>
                </div>
            </a>
        @endforeach
    </div>
    <div class="mt-4">{{ $recipes->links() }}</div>
@endif
</div>
@endsection
