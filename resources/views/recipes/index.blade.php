@extends('layouts.app')
@section('title', 'Recetas')

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Recetas</h1>
        <p class="text-sm text-slate-500">El costo sale de lo que usa cada receta, con el precio más barato de cada artículo.</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('menu.index') }}" class="btn btn-secondary">Menú de la semana</a>
        <a href="{{ route('recipes.create') }}" class="btn btn-primary">+ Nueva receta</a>
    </div>
</div>

<form method="GET" class="mb-4 flex flex-wrap gap-2">
    <input name="q" value="{{ request('q') }}" class="input max-w-xs" placeholder="Buscar receta…">
    <select name="category" class="input max-w-48" onchange="this.form.submit()">
        <option value="">Todas las categorías</option>
        @foreach ($categories as $category)
            <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
        @endforeach
    </select>
    <button class="btn btn-secondary">Buscar</button>
    @if (request()->hasAny(['q', 'category']))
        <a href="{{ route('recipes.index') }}" class="btn btn-ghost">Limpiar</a>
    @endif
</form>

<div class="card overflow-x-auto p-0">
    <table class="table">
        <thead>
        <tr><th>Receta</th><th>Categoría</th><th class="text-right">Porciones</th><th class="text-right">Tiempo</th><th class="text-right">Costo</th><th class="text-right">Por porción</th><th></th></tr>
        </thead>
        <tbody>
        @forelse ($recipes as $recipe)
            @php($uncosted = $recipe->uncostedCount())
            <tr>
                <td>
                    <div class="flex items-center gap-3">
                        @if ($cover = $recipe->coverPhoto())
                            <img src="{{ $cover->url(true) }}" alt="" loading="lazy" class="size-12 shrink-0 rounded-md object-cover ring-1 ring-slate-200">
                        @endif
                        <div class="min-w-0">
                            <a href="{{ route('recipes.show', $recipe) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $recipe->name }}</a>
                            <div class="text-xs text-slate-500">{{ $recipe->ingredients->count() }} ingredientes</div>
                        </div>
                    </div>
                </td>
                <td>@if ($recipe->category)<span class="badge badge-muted">{{ $recipe->category }}</span>@endif</td>
                <td class="text-right tabular-nums">{{ $recipe->servings }}</td>
                <td class="text-right tabular-nums text-slate-500">{{ $recipe->minutes ? $recipe->minutes.' min' : '—' }}</td>
                <td class="text-right tabular-nums">
                    {{ money($recipe->cost()) }}
                    @if ($uncosted && $recipe->cost() !== null)
                        <div class="text-xs text-amber-700" title="Ingredientes sin artículo, sin cantidad o sin precio">+ {{ $uncosted }} sin costo</div>
                    @endif
                </td>
                <td class="text-right font-semibold tabular-nums">{{ money($recipe->costPerServing()) }}</td>
                <td class="text-right"><a href="{{ route('recipes.show', $recipe) }}" class="btn btn-ghost btn-sm">Abrir →</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-10 text-center text-slate-500">
                @if (request()->hasAny(['q', 'category']))
                    Ninguna receta coincide.
                @else
                    No hay recetas todavía. <a class="link" href="{{ route('recipes.create') }}">Agrega la primera</a>.
                @endif
            </td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
