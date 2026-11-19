@extends('layouts.app')
@section('title', 'Menú de la semana')

@section('content')
@php($thisWeek = $start->isSameDay(today()->startOfWeek(\Carbon\CarbonInterface::MONDAY)))
<div x-data="{ day: {{ Js::from(old('planned_on', today()->betweenIncluded($start, $end) ? today()->toDateString() : $start->toDateString())) }}, meal: {{ Js::from(old('meal', 'lunch')) }}, recipe: {{ Js::from((string) old('recipe_id', '')) }},
               pick(day, meal) { this.day = day; this.meal = meal; const el = document.getElementById('menu-recipe'); el.scrollIntoView({ block: 'center', behavior: 'smooth' }); el.focus({ preventScroll: true }); } }">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Menú de la semana</h1>
            <p class="text-sm text-slate-500">Del {{ $start->translatedFormat('d \d\e F') }} al {{ $end->translatedFormat('d \d\e F Y') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('menu.index', ['week' => $start->subWeek()->toDateString()]) }}" class="btn btn-secondary">← Anterior</a>
            @unless ($thisWeek)
                <a href="{{ route('menu.index') }}" class="btn btn-ghost">Esta semana</a>
            @endunless
            <a href="{{ route('menu.index', ['week' => $start->addWeek()->toDateString()]) }}" class="btn btn-secondary">Siguiente →</a>
        </div>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <form method="POST" action="{{ route('menu.store') }}" class="card lg:col-span-2">
            @csrf
            <h2 class="card-title">Agregar al menú</h2>
            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-6">
                <div class="sm:col-span-2">
                    <label class="label" for="menu-day">Día</label>
                    <input id="menu-day" name="planned_on" type="date" x-model="day" class="input" required>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="menu-meal">Comida</label>
                    <select id="menu-meal" name="meal" x-model="meal" class="input">
                        @foreach ($meals as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="menu-servings">Porciones <span class="font-normal text-slate-400">(las de la receta)</span></label>
                    <input id="menu-servings" name="servings" type="number" min="1" max="999" value="{{ old('servings') }}" class="input" :disabled="!recipe">
                </div>
                <div class="sm:col-span-3">
                    <label class="label" for="menu-recipe">Receta</label>
                    <select id="menu-recipe" name="recipe_id" x-model="recipe" class="input">
                        <option value="">Sin receta (escribe qué)</option>
                        @foreach ($recipes as $recipe)
                            <option value="{{ $recipe->id }}">{{ $recipe->name }} ({{ $recipe->servings }} porc.)</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-3" x-show="!recipe">
                    <label class="label" for="menu-title">Qué se va a comer</label>
                    <input id="menu-title" name="title" value="{{ old('title') }}" class="input" placeholder="Ej. Sobras, comer fuera" maxlength="150" :disabled="!!recipe">
                </div>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <button class="btn btn-primary">Agregar</button>
                @if ($recipes->isEmpty())
                    <span class="text-xs text-slate-500">Aún no tienes recetas. <a class="link" href="{{ route('recipes.create') }}">Crea una</a> para ver costos y mandar ingredientes a tu lista.</span>
                @endif
            </div>
        </form>

        <div class="card flex flex-col">
            <div class="stat-label">Costo estimado de la semana</div>
            <div class="stat-value">{{ money($total) }}</div>
            <div class="mt-1 text-xs text-slate-500">{{ $entries->count() }} {{ $entries->count() === 1 ? 'comida planeada' : 'comidas planeadas' }} · lo que se usa, al precio más barato</div>
            <form method="POST" action="{{ route('menu.shop') }}" class="mt-auto space-y-2 pt-4">
                @csrf
                <input type="hidden" name="week" value="{{ $start->toDateString() }}">
                <label class="label" for="menu-list">Mandar ingredientes a</label>
                <select id="menu-list" name="list_id" class="input">
                    <option value="">Lista nueva para esta semana</option>
                    @foreach ($lists as $list)
                        <option value="{{ $list->id }}">{{ $list->name }} ({{ $list->shopped_on->translatedFormat('d M') }})</option>
                    @endforeach
                </select>
                <button class="btn btn-secondary w-full" @disabled($entries->whereNotNull('recipe_id')->isEmpty())>Mandar a lista de compras</button>
                <p class="text-xs text-slate-500">Se suma a lo que ya tenga la lista: mándalo una sola vez por semana.</p>
            </form>
        </div>
    </div>

    <div class="space-y-3">
        @foreach ($days as $day)
            @php($isToday = $day['date']->isToday())
            <div class="card p-0 {{ $isToday ? 'ring-2 ring-emerald-400' : '' }}">
                <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2">
                    <h2 class="card-title">{{ ucfirst($day['date']->translatedFormat('l d')) }}</h2>
                    @if ($isToday)<span class="badge badge-cheap">Hoy</span>@endif
                </div>
                <div class="grid grid-cols-1 divide-y divide-slate-100 sm:grid-cols-4 sm:divide-x sm:divide-y-0">
                    @foreach ($day['meals'] as $meal => $mealEntries)
                        <div class="min-w-0 px-4 py-3">
                            <div class="mb-1 flex items-center justify-between">
                                <span class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ $meals[$meal] }}</span>
                                <button type="button" class="btn btn-ghost btn-sm" title="Agregar a {{ $meals[$meal] }}"
                                        @click="pick('{{ $day['date']->toDateString() }}', '{{ $meal }}')">+</button>
                            </div>
                            @foreach ($mealEntries as $entry)
                                <div class="flex items-start justify-between gap-2 py-1 text-sm">
                                    <div class="min-w-0">
                                        @if ($entry->recipe)
                                            <a href="{{ route('recipes.show', ['recipe' => $entry->recipe, 'servings' => $entry->plannedServings()]) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $entry->recipe->name }}</a>
                                            <div class="text-xs text-slate-500">{{ $entry->plannedServings() }} porc.@if ($entry->cost() !== null) · {{ money($entry->cost()) }}@endif</div>
                                        @else
                                            <span class="text-slate-700">{{ $entry->title }}</span>
                                        @endif
                                    </div>
                                    <form method="POST" action="{{ route('menu.destroy', $entry) }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-danger btn-sm" title="Quitar del menú">✕</button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
