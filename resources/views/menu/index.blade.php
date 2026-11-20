@extends('layouts.app')
@section('title', 'Menú de la semana')

@section('content')
@php $thisWeek = $start->isSameDay(today()->startOfWeek(\Carbon\CarbonInterface::MONDAY)); @endphp
@php
    // Si el alta falló (validación), se reabre el mismo «+» con lo que se había escrito
    $oldKey = $errors->any() && old('planned_on') && old('meal') ? old('planned_on').'|'.old('meal') : '';
    $oldRecipe = old('recipe_id') ? (string) old('recipe_id') : (old('title') ? 'other' : '');
@endphp
{{-- Agregar al menú: el «+» de cada comida abre ahí mismo el formulario (uno solo, que se mueve a esa comida) --}}
<div x-data="{ open: {{ Js::from($oldKey) }}, recipe: {{ Js::from($oldRecipe) }}, servings: {{ Js::from((string) old('servings', '')) }}, title: {{ Js::from((string) old('title', '')) }},
               get day() { return this.open.split('|')[0] ?? '' }, get meal() { return this.open.split('|')[1] ?? '' },
               init() { if (this.open) this.place() },
               pick(key) {
                   if (this.open === key) { this.open = ''; return }
                   this.open = key; this.recipe = ''; this.servings = ''; this.title = ''; this.place();
               },
               place() {
                   this.$nextTick(() => {
                       const form = document.getElementById('menu-add');
                       document.getElementById('add-' + this.open)?.appendChild(form);
                       form.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                       document.getElementById('menu-add-recipe').focus({ preventScroll: true });
                   });
               },
               chosen() {
                   const option = document.getElementById('menu-add-recipe').selectedOptions[0];
                   this.servings = option?.dataset.servings ?? '';
                   if (this.recipe === 'other') this.$nextTick(() => document.getElementById('menu-add-title').focus());
               } }">
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

    <div class="card mb-6 flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
        <div>
            <div class="stat-label">Costo estimado de la semana</div>
            <div class="stat-value">{{ money($total) }}</div>
            <div class="mt-1 text-xs text-slate-500">{{ $entries->count() }} {{ $entries->count() === 1 ? 'comida planeada' : 'comidas planeadas' }} · lo que se usa, al precio más barato</div>
            @if ($avgKcal)
                <div class="mt-1 text-xs text-slate-500">Promedio por persona: <span class="font-semibold text-slate-700">{{ number_format($avgKcal) }} kcal</span> al día</div>
            @endif
        </div>
        <div class="w-full space-y-2 sm:w-72">
            @if ($entries->whereNotNull('recipe_id')->isEmpty())
                <button class="btn btn-secondary w-full" disabled>Mandar a lista de compras</button>
                <p class="text-xs text-slate-500">Agrega recetas al menú con el «+» de cada comida para mandar sus ingredientes a una lista.</p>
            @else
                <a href="{{ route('menu.shop.review', ['week' => $start->toDateString()]) }}" class="btn btn-secondary w-full">Mandar a lista de compras</a>
                <p class="text-xs text-slate-500">Antes de agregar revisas qué falta: lo que está en tu despensa no se manda.</p>
            @endif
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
                               edit() { this.draft = this.body; this.error = ''; this.editing = true; this.$nextTick(() => document.getElementById('note-{{ $dateKey }}')?.focus()) },
                               async save(text) {
                                   if (this.saving) return;
                                   if (text.trim() === this.body) { this.editing = false; return }
                                   this.saving = true; this.error = '';
                                   try {
                                       const res = await fetch('{{ route('menu.note', $dateKey) }}', { method: 'PUT', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ body: text }) });
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
                        {{-- Nota del día ("Mealprep: cocer arroz y pollo"): el icono abre una ventana para verla, editarla o borrarla --}}
                        <button type="button" @click="edit()" class="icon-btn ml-auto size-8 shrink-0"
                                :class="body ? 'bg-amber-50 text-amber-700 ring-1 ring-amber-200 hover:ring-amber-400' : 'text-slate-400'"
                                :title="body || 'Nota del día'" :aria-label="body ? 'Ver la nota del día' : 'Agregar nota del día'" aria-haspopup="dialog"><x-icon name="sticky-note" class="size-4" /></button>
                    </div>
                    <template x-teleport="body">
                        <div x-show="editing" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4"
                             @click.self="editing = false" @keydown.escape.window="editing = false"
                             role="dialog" aria-modal="true" aria-labelledby="note-title-{{ $dateKey }}">
                            <form class="flex max-h-[90vh] w-full max-w-md flex-col gap-3 rounded-t-3xl bg-surface p-5 shadow-2xl sm:rounded-2xl" @submit.prevent="save(draft)" data-no-busy>
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <h2 id="note-title-{{ $dateKey }}" class="font-display text-xl font-semibold text-slate-900">Nota del día</h2>
                                        <p class="text-sm text-slate-500 capitalize">{{ $day['date']->translatedFormat('l j \d\e F') }}</p>
                                    </div>
                                    <button type="button" class="icon-btn shrink-0" @click="editing = false" aria-label="Cerrar"><x-icon name="x" class="size-5" /></button>
                                </div>
                                <textarea id="note-{{ $dateKey }}" x-model="draft" maxlength="500" rows="7" class="input text-base" placeholder="Ej. Mealprep: cocer arroz y pollo"
                                          aria-labelledby="note-title-{{ $dateKey }}" @keydown.enter="($event.metaKey || $event.ctrlKey) && save(draft)"></textarea>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-red-600" x-text="error"></span>
                                    <span class="text-slate-500 tabular-nums" x-text="draft.length + ' / 500'"></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" x-show="body" class="btn btn-ghost text-red-600" :disabled="saving" @click="save('')"><x-icon name="trash" class="size-4" /> Eliminar</button>
                                    <button type="button" class="btn btn-secondary ml-auto" @click="editing = false">Cancelar</button>
                                    <button class="btn btn-primary" :disabled="saving || !draft.trim()" x-text="saving ? 'Guardando…' : 'Guardar'"></button>
                                </div>
                            </form>
                        </div>
                    </template>
                </header>

                @foreach ($day['meals'] as $meal => $slot)
                    {{-- En celular las comidas vacías no ocupan lugar: se agregan con los botones de abajo --}}
                    @php $slotKey = $dateKey.'|'.$meal; @endphp
                    <div class="min-w-0 border-b border-slate-100 px-3 py-2 {{ $slot['items']->isEmpty() && $oldKey !== $slotKey ? 'max-xl:hidden' : '' }}"
                         @if ($slot['items']->isEmpty()) :class="{ 'max-xl:hidden': open !== '{{ $slotKey }}' }" @endif>
                        <div class="mb-1 flex items-center justify-between gap-1">
                            <span class="text-[11px] font-semibold tracking-wide text-slate-500 uppercase">{{ $meals[$meal] }}</span>
                            @if ($slot['items']->count() > 1)
                                <span class="text-[11px] text-slate-500 tabular-nums">{{ money($slot['cost']) }}@if ($slot['macros']) · {{ $slot['partial'] ? '~' : '' }}{{ number_format($slot['macros']['kcal']) }} kcal @endif</span>
                            @endif
                            <button type="button" class="icon-btn size-7 text-slate-400" title="Agregar a {{ $meals[$meal] }}" aria-label="Agregar a {{ $meals[$meal] }} del {{ $day['date']->translatedFormat('l d') }}"
                                    :aria-expanded="open === '{{ $slotKey }}'" @click="pick('{{ $slotKey }}')"><x-icon name="plus" class="size-4" /></button>
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
                        {{-- Aquí se coloca el formulario de agregar al tocar el «+» de esta comida --}}
                        <div id="add-{{ $slotKey }}"></div>
                    </div>
                @endforeach

                @if ($empty->isNotEmpty())
                    <div class="flex flex-wrap gap-1 border-b border-slate-100 px-2 py-2 xl:hidden">
                        @foreach ($empty as $meal => $_)
                            <button type="button" class="btn btn-ghost btn-sm" x-show="open !== '{{ $dateKey }}|{{ $meal }}'" @click="pick('{{ $dateKey }}|{{ $meal }}')">+ {{ $meals[$meal] }}</button>
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
    {{-- El formulario de agregar: vive aquí escondido y se mueve a la comida cuyo «+» se tocó --}}
    <form id="menu-add" method="POST" action="{{ route('menu.store') }}" x-show="open" x-cloak
          class="mt-2 space-y-2 rounded-xl bg-surface p-2 ring-1 ring-emerald-300" @keydown.escape.prevent="open = ''">
        @csrf
        <input type="hidden" name="planned_on" :value="day">
        <input type="hidden" name="meal" :value="meal">
        <input type="hidden" name="recipe_id" :value="recipe === 'other' ? '' : recipe">
        <select id="menu-add-recipe" x-model="recipe" @change="chosen()" class="input py-1.5 text-sm" aria-label="Qué agregar" required>
            <option value="" disabled>Elegir…</option>
            @foreach ($recipes as $recipe)
                <option value="{{ $recipe->id }}" data-servings="{{ $recipe->servings }}">{{ $recipe->name }}</option>
            @endforeach
            <option value="other">Otra cosa (sin receta)…</option>
        </select>
        <input id="menu-add-title" name="title" x-model="title" x-show="recipe === 'other'" :disabled="recipe !== 'other'" :required="recipe === 'other'"
               class="input py-1.5 text-sm" placeholder="Ej. Sobras, comer fuera" maxlength="150" aria-label="Qué se va a comer">
        <div class="flex flex-wrap items-center justify-end gap-1.5">
            <label for="menu-add-servings" class="text-xs text-slate-500 xl:basis-full 2xl:basis-auto" x-show="recipe && recipe !== 'other'">Porciones</label>
            <input id="menu-add-servings" name="servings" type="number" inputmode="numeric" min="1" max="999" x-model="servings"
                   x-show="recipe && recipe !== 'other'" :disabled="!recipe || recipe === 'other'" class="input mr-auto w-14 px-1 py-1.5 text-center text-sm">
            <button type="button" class="icon-btn size-8 shrink-0 text-slate-500" title="Cancelar" aria-label="Cancelar" @click="open = ''"><x-icon name="x" class="size-4" /></button>
            <button class="btn btn-primary btn-sm shrink-0 xl:px-2 2xl:px-3" title="Agregar al menú" aria-label="Agregar al menú"><x-icon name="check" class="size-4" /><span class="xl:hidden 2xl:inline">Agregar</span></button>
        </div>
        @if ($recipes->isEmpty())
            <p class="text-xs text-slate-500">Aún no tienes recetas. <a class="link" href="{{ route('recipes.create') }}">Crea una</a> para ver costos y mandar ingredientes a tu lista.</p>
        @endif
    </form>

    <p class="mt-3 text-xs text-slate-500">Costo: las porciones planeadas, al precio más barato. Macros (P proteína, C carbohidratos, G grasa, en g): una porción de cada platillo, lo que come una persona. «~» = faltan datos de algún ingrediente.</p>
</div>
@endsection
