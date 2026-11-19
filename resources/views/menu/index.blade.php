@extends('layouts.app')
@section('title', 'Menú de la semana')

@section('content')
@php $thisWeek = $start->isSameDay(today()->startOfWeek(\Carbon\CarbonInterface::MONDAY)); @endphp
<div x-data="{ day: {{ Js::from(old('planned_on', today()->betweenIncluded($start, $end) ? today()->toDateString() : $start->toDateString())) }}, meal: {{ Js::from(old('meal', 'lunch')) }}, recipe: {{ Js::from((string) old('recipe_id', '')) }},
               adding: {{ $errors->any() ? 'true' : 'false' }},
               pick(day, meal) { this.day = day; this.meal = meal; this.adding = true; this.$nextTick(() => { const el = document.getElementById('menu-recipe'); el.scrollIntoView({ block: 'center', behavior: 'smooth' }); el.focus({ preventScroll: true }); }); } }">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Menú de la semana</h1>
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
        {{-- En celular se abre con el botón o con los «+» de cada día, para que la semana quede a la vista --}}
        <button type="button" class="btn btn-primary lg:hidden" x-show="!adding" @click="pick(day, meal)"><x-icon name="plus" class="size-4" /> Agregar comida</button>
        <form method="POST" action="{{ route('menu.store') }}" class="card lg:col-span-2" :class="!adding && 'max-lg:hidden'">
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
            @if ($avgKcal)
                <div class="mt-1 text-xs text-slate-500">Promedio por persona: <span class="font-semibold text-slate-700">{{ number_format($avgKcal) }} kcal</span> al día</div>
            @endif
            <div class="mt-auto space-y-2 pt-4">
                @if ($entries->whereNotNull('recipe_id')->isEmpty())
                    <button class="btn btn-secondary w-full" disabled>Mandar a lista de compras</button>
                    <p class="text-xs text-slate-500">Agrega recetas al menú para mandar sus ingredientes a una lista.</p>
                @else
                    <a href="{{ route('menu.shop.review', ['week' => $start->toDateString()]) }}" class="btn btn-secondary w-full">Mandar a lista de compras</a>
                    <p class="text-xs text-slate-500">Antes de agregar revisas qué falta: lo que está en tu despensa no se manda.</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Calendario: en pantalla ancha una columna por día con las comidas alineadas (subgrid);
         en celular, un día debajo de otro. Costo = las porciones planeadas; macros = una porción. --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-7 xl:gap-2">
        @foreach ($days as $day)
            @php
                $isToday = $day['date']->isToday();
                $dateKey = $day['date']->toDateString();
                $empty = $day['meals']->filter(fn ($m) => $m['items']->isEmpty());
            @endphp
            <section class="card flex flex-col p-0 xl:row-span-7 xl:grid xl:grid-rows-subgrid xl:gap-0 {{ $isToday ? 'ring-2 ring-emerald-400' : '' }}"
                     aria-label="{{ ucfirst($day['date']->translatedFormat('l d')) }}">
                <header class="border-b border-slate-100 px-3 py-2"
                     x-data="{ body: {{ Js::from($notes->get($dateKey)?->body ?? '') }}, draft: '', editing: false, saving: false, error: '',
                               edit() { this.draft = this.body; this.editing = true; this.$nextTick(() => document.getElementById('note-{{ $dateKey }}')?.focus()) },
                               async save() {
                                   if (!this.editing || this.saving) return;
                                   if (this.draft.trim() === this.body) { this.editing = false; return }
                                   this.saving = true; this.error = '';
                                   try {
                                       const res = await fetch('{{ route('menu.note', $dateKey) }}', { method: 'PUT', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ body: this.draft }) });
                                       const data = await res.json();
                                       if (!res.ok) throw new Error(data.message || 'No se pudo guardar');
                                       this.body = data.body ?? ''; this.editing = false;
                                   } catch (e) { this.error = e.message } finally { this.saving = false }
                               } }">
                    <div class="flex items-center gap-2">
                        <div class="flex min-w-0 items-baseline gap-1.5">
                            <span class="font-display text-2xl leading-none font-semibold {{ $isToday ? 'text-emerald-700' : 'text-slate-900' }}">{{ $day['date']->format('j') }}</span>
                            <span class="truncate text-sm font-medium capitalize {{ $isToday ? 'text-emerald-700' : 'text-slate-600' }}">{{ $day['date']->translatedFormat('l') }}</span>
                        </div>
                        @if ($isToday)<span class="badge badge-cheap xl:hidden">Hoy</span>@endif
                        <button type="button" x-show="!editing && !body" @click="edit()" class="icon-btn ml-auto size-8 shrink-0 text-slate-400" title="Nota del día" aria-label="Agregar nota del día"><x-icon name="sticky-note" class="size-4" /></button>
                    </div>
                    {{-- Nota del día: "Mealprep: cocer arroz y pollo" --}}
                    <button type="button" x-show="!editing && body" @click="edit()" class="mt-1.5 flex w-full min-w-0 items-start gap-1.5 rounded-lg bg-amber-50 px-2 py-1 text-left text-xs text-amber-900 ring-1 ring-amber-200 hover:ring-amber-400" title="Editar la nota del día">
                        <x-icon name="sticky-note" class="mt-0.5 size-3 shrink-0 text-amber-600" /><span x-text="body"></span>
                    </button>
                    <form x-show="editing" x-cloak class="mt-1.5 flex items-center gap-1.5" @submit.prevent="save()">
                        <input :id="'note-{{ $dateKey }}'" type="text" x-model="draft" maxlength="500" class="input py-1 text-sm" placeholder="Ej. Mealprep: cocer arroz"
                               aria-label="Nota del {{ $day['date']->translatedFormat('l d') }}" @keydown.escape.prevent="editing = false" @blur="save()">
                        <button class="btn btn-primary btn-sm" :disabled="saving" @mousedown.prevent><x-icon name="check" class="size-4" /></button>
                    </form>
                    <p x-show="error" class="text-xs text-red-600" x-text="error"></p>
                </header>

                @foreach ($day['meals'] as $meal => $slot)
                    {{-- En celular las comidas vacías no ocupan lugar: se agregan con los botones de abajo --}}
                    <div class="min-w-0 border-b border-slate-100 px-3 py-2 {{ $slot['items']->isEmpty() ? 'hidden xl:block' : '' }}">
                        <div class="mb-1 flex items-center justify-between gap-1">
                            <span class="text-[11px] font-semibold tracking-wide text-slate-500 uppercase">{{ $meals[$meal] }}</span>
                            @if ($slot['items']->count() > 1)
                                <span class="text-[11px] text-slate-500 tabular-nums">{{ money($slot['cost']) }}@if ($slot['macros']) · {{ $slot['partial'] ? '~' : '' }}{{ number_format($slot['macros']['kcal']) }} kcal @endif</span>
                            @endif
                            <button type="button" class="icon-btn size-7 text-slate-400" title="Agregar a {{ $meals[$meal] }}" aria-label="Agregar a {{ $meals[$meal] }} del {{ $day['date']->translatedFormat('l d') }}"
                                    @click="pick('{{ $dateKey }}', '{{ $meal }}')"><x-icon name="plus" class="size-4" /></button>
                        </div>
                        <div class="space-y-2">
                            @foreach ($slot['items'] as $item)
                                @php $entry = $item['entry']; @endphp
                                <article class="group relative flex overflow-hidden rounded-xl bg-slate-50 ring-1 ring-slate-200 xl:block">
                                    @if ($item['photo'])
                                        {{-- En celular, miniatura al lado; en el calendario ancho, arriba --}}
                                        <img src="{{ $item['photo'] }}" alt="" loading="lazy" class="size-20 shrink-0 object-cover xl:aspect-[4/3] xl:h-auto xl:w-full">
                                    @endif
                                    <div class="min-w-0 flex-1 p-2 pr-9 xl:pr-2">
                                        @if ($entry->recipe)
                                            <a href="{{ route('recipes.show', ['recipe' => $entry->recipe, 'servings' => $entry->plannedServings()]) }}" class="block text-sm leading-snug font-medium text-slate-900 hover:text-emerald-700">{{ $entry->recipe->name }}</a>
                                            <div class="mt-0.5 text-xs text-slate-500 tabular-nums">
                                                {{ $entry->plannedServings() }} porc.@if ($item['cost'] !== null) · <span class="font-medium text-slate-700">{{ money($item['cost']) }}</span>@endif
                                            </div>
                                            @if ($item['macros'])
                                                <div class="mt-1 flex flex-wrap gap-x-2 text-[11px] text-slate-500 tabular-nums" title="Por porción{{ $item['macros']['missing'] ? ' (faltan datos de algún ingrediente)' : '' }}">
                                                    <span class="font-semibold text-slate-700">{{ $item['macros']['missing'] ? '~' : '' }}{{ number_format($item['macros']['kcal']) }} kcal</span>
                                                    <span>P {{ num($item['macros']['protein']) }}</span><span>C {{ num($item['macros']['carbs']) }}</span><span>G {{ num($item['macros']['fat']) }}</span>
                                                </div>
                                            @endif
                                        @else
                                            <span class="text-sm text-slate-700">{{ $entry->title }}</span>
                                        @endif
                                    </div>
                                    <form method="POST" action="{{ route('menu.destroy', $entry) }}" class="absolute top-1 right-1">
                                        @csrf @method('DELETE')
                                        <button class="icon-btn size-7 bg-surface/90 text-red-600 shadow-sm" title="Quitar del menú" aria-label="Quitar {{ $entry->label() }} del menú"><x-icon name="x" class="size-3.5" /></button>
                                    </form>
                                </article>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                @if ($empty->isNotEmpty())
                    <div class="flex flex-wrap gap-1 border-b border-slate-100 px-2 py-2 xl:hidden">
                        @foreach ($empty as $meal => $_)
                            <button type="button" class="btn btn-ghost btn-sm" @click="pick('{{ $dateKey }}', '{{ $meal }}')">+ {{ $meals[$meal] }}</button>
                        @endforeach
                    </div>
                @endif

                {{-- Total del día: lo que cuesta lo planeado y lo que come una persona --}}
                <footer class="mt-auto rounded-b-2xl bg-slate-50 px-3 py-2 text-xs">
                    @if ($day['count'])
                        <div class="flex flex-wrap items-baseline justify-between gap-x-2">
                            <span class="font-semibold text-slate-500">Total del día</span>
                            <span class="font-display text-base font-semibold text-slate-900 tabular-nums">{{ money($day['cost']) }}</span>
                        </div>
                        @if ($day['macros'])
                            <div class="mt-0.5 text-slate-600 tabular-nums" title="Por persona: una porción de cada platillo{{ $day['partial'] ? '; aproximado, faltan datos' : '' }}">
                                <span class="font-semibold text-slate-800">{{ $day['partial'] ? '~' : '' }}{{ number_format($day['macros']['kcal']) }} kcal</span>
                                · P {{ num($day['macros']['protein']) }} · C {{ num($day['macros']['carbs']) }} · G {{ num($day['macros']['fat']) }}
                            </div>
                        @endif
                    @else
                        <span class="text-slate-400">Nada planeado</span>
                    @endif
                </footer>
            </section>
        @endforeach
    </div>
    <p class="mt-3 text-xs text-slate-500">Costo: las porciones planeadas, al precio más barato. Macros (P proteína, C carbohidratos, G grasa, en g): una porción de cada platillo, lo que come una persona. «~» = faltan datos de algún ingrediente.</p>
</div>
@endsection
