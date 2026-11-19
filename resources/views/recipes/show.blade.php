@extends('layouts.app')
@section('title', $recipe->name)

@section('content')
@php
    $cost = $recipe->cost($servings);
    $uncosted = $recipe->uncostedCount();
    $nutrition = $recipe->nutrition($servings);
    $portion = $recipe->gramsPerServing();
@endphp
<a href="{{ route('recipes.index') }}" class="link text-sm">← Recetas</a>
<div class="mt-2 mb-6 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ $recipe->name }}</h1>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
            @if ($recipe->category)<span class="badge badge-muted">{{ $recipe->category }}</span>@endif
            <span>Rinde {{ $recipe->servings }} {{ $recipe->servings === 1 ? 'porción' : 'porciones' }}@if ($portion) de unos {{ number_format($portion) }} g en crudo @endif</span>
            @if ($recipe->minutes)<span>· {{ $recipe->minutes }} min</span>@endif
            @if ($recipe->source_url)<span>· <a href="{{ $recipe->source_url }}" target="_blank" rel="noopener" class="link">Receta original</a></span>@endif
        </p>
        @if ($recipe->notes)<p class="mt-1 text-sm text-slate-600">{{ $recipe->notes }}</p>@endif
    </div>
    <a href="{{ route('recipes.edit', $recipe) }}" class="btn btn-secondary">Editar</a>
</div>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        @if ($cover = $recipe->coverPhoto())
            <a href="{{ $cover->url() }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-xl ring-1 ring-slate-200">
                <img src="{{ $cover->url() }}" alt="{{ $recipe->name }}" class="max-h-96 w-full object-cover">
            </a>
        @endif
        <div class="card overflow-x-auto p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-5 pb-3">
                <h2 class="card-title">Ingredientes</h2>
                <form method="GET" class="flex items-center gap-2 text-sm">
                    <label for="servings" class="text-slate-600">Porciones</label>
                    <input id="servings" name="servings" type="number" min="1" max="999" value="{{ $servings }}" class="input w-20">
                    <button class="btn btn-secondary btn-sm">Recalcular</button>
                    @if ($servings !== $recipe->servings)
                        <a href="{{ route('recipes.show', $recipe) }}" class="btn btn-ghost btn-sm">Original</a>
                    @endif
                </form>
            </div>
            <table class="table">
                <thead><tr><th class="text-right">Cantidad</th><th>Ingrediente</th><th class="text-right">Costo</th></tr></thead>
                <tbody>
                @forelse ($recipe->ingredients as $ingredient)
                    @php($price = $ingredient->product?->cheapestPrice())
                    <tr>
                        <td class="text-right whitespace-nowrap tabular-nums">{{ $ingredient->quantityLabel($factor) }}</td>
                        <td>
                            @if ($ingredient->product)
                                <a href="{{ route('products.show', $ingredient->product) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $ingredient->product->name }}</a>
                            @else
                                <span class="font-medium text-slate-900">{{ $ingredient->name }}</span>
                            @endif
                            @if ($ingredient->note)<span class="text-slate-500">, {{ $ingredient->note }}</span>@endif
                        </td>
                        <td class="text-right whitespace-nowrap tabular-nums">
                            @if ($ingredient->cost($factor) !== null)
                                {{ money($ingredient->cost($factor)) }}
                                <div class="text-xs text-slate-500">{{ $price->provider->name }} · {{ money($price->comparePrice()) }}{{ $price->compareLabel() }}</div>
                            @elseif ($ingredient->product)
                                <span class="text-xs text-amber-700">Sin precio</span>
                            @else
                                <span class="text-xs text-slate-400">Sin artículo</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-8 text-center text-slate-500">Esta receta no tiene ingredientes. <a class="link" href="{{ route('recipes.edit', $recipe) }}">Agrégalos</a>.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2 class="card-title">Preparación</h2>
            @if ($recipe->steps())
                <ol class="mt-3 list-outside list-decimal space-y-2 pl-5 text-sm text-slate-700">
                    @foreach ($recipe->steps() as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ol>
            @else
                <p class="mt-2 text-sm text-slate-500">Sin pasos escritos.</p>
            @endif
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="stat-label">Costo para {{ $servings }} {{ $servings === 1 ? 'porción' : 'porciones' }}</div>
            <div class="stat-value">{{ money($cost) }}</div>
            <div class="mt-1 text-xs text-slate-500">
                @if ($cost !== null)
                    {{ money($cost / $servings) }} por porción · lo que se usa, al precio más barato por unidad
                @else
                    Liga ingredientes a artículos con precio para ver el costo.
                @endif
            </div>
            @if ($uncosted && $cost !== null)
                <div class="mt-2 text-xs text-amber-700">{{ $uncosted }} {{ $uncosted === 1 ? 'ingrediente no entra' : 'ingredientes no entran' }} en el costo (sin artículo, sin cantidad o sin precio).</div>
            @endif
        </div>

        <div class="card">
            <div class="stat-label">Por porción</div>
            @if ($nutrition)
                <div class="stat-value">{{ number_format($nutrition['perServing']['kcal']) }} <span class="text-base font-normal text-slate-500">kcal</span></div>
                <dl class="mt-3 grid grid-cols-3 gap-2 text-center text-sm">
                    @foreach (['protein' => 'Proteína', 'carbs' => 'Carbohidratos', 'fat' => 'Grasa'] as $key => $label)
                        <div class="rounded-lg bg-slate-50 px-2 py-2 ring-1 ring-slate-200">
                            <dt class="text-xs text-slate-500">{{ $label }}</dt>
                            <dd class="font-semibold tabular-nums">{{ num($nutrition['perServing'][$key]) }} g</dd>
                        </div>
                    @endforeach
                </dl>
                <div class="mt-2 text-xs text-slate-500">
                    Toda la receta ({{ $servings }} porc.): {{ number_format($nutrition['total']['kcal']) }} kcal · P {{ num($nutrition['total']['protein']) }} g · C {{ num($nutrition['total']['carbs']) }} g · G {{ num($nutrition['total']['fat']) }} g
                </div>
                @if ($nutrition['missing'])
                    <div class="mt-2 text-xs text-amber-700">{{ $nutrition['missing'] }} {{ $nutrition['missing'] === 1 ? 'artículo no tiene' : 'artículos no tienen' }} datos de nutrición; agrégalos en su ficha.</div>
                @endif
            @else
                <p class="mt-2 text-sm text-slate-500">Agrega la nutrición de los artículos (kcal, proteína, carbohidratos y grasa) para ver los macros. Se llenan solos al buscar su código de barras.</p>
            @endif
        </div>

        <form method="POST" action="{{ route('menu.store') }}" class="card space-y-3">
            @csrf
            <h2 class="card-title">Agregar al menú</h2>
            <input type="hidden" name="recipe_id" value="{{ $recipe->id }}">
            <input type="hidden" name="servings" value="{{ $servings }}">
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="label" for="planned_on">Día</label>
                    <input id="planned_on" name="planned_on" type="date" value="{{ old('planned_on', today()->toDateString()) }}" class="input" required>
                </div>
                <div>
                    <label class="label" for="meal">Comida</label>
                    <select id="meal" name="meal" class="input">
                        @foreach ($meals as $value => $label)
                            <option value="{{ $value }}" @selected(old('meal', 'lunch') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <button class="btn btn-secondary w-full">Agregar ({{ $servings }} {{ $servings === 1 ? 'porción' : 'porciones' }})</button>
        </form>

        <form method="POST" action="{{ route('recipes.shop', $recipe) }}" class="card space-y-3">
            @csrf
            <h2 class="card-title">Mandar a una lista de compras</h2>
            <input type="hidden" name="servings" value="{{ $servings }}">
            <div>
                <label class="label" for="list_id">Lista</label>
                <select id="list_id" name="list_id" class="input">
                    <option value="">Lista nueva «{{ $recipe->name }}»</option>
                    @foreach ($lists as $list)
                        <option value="{{ $list->id }}">{{ $list->name }} ({{ $list->shopped_on->translatedFormat('d M') }})</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">Lo necesario para {{ $servings }} {{ $servings === 1 ? 'porción' : 'porciones' }} se suma a lo que ya tenga la lista.</p>
            </div>
            <button class="btn btn-primary w-full">Agregar ingredientes</button>
        </form>

        @include('attachments._panel', ['owner' => $recipe, 'action' => route('recipes.attachments.store', $recipe), 'title' => 'Fotos', 'hint' => 'La primera es la portada de la receta.'])
    </div>
</div>
@endsection
