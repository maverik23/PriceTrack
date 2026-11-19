@extends('layouts.app')
@section('title', $recipe->name)

@section('content')
@php
    $view = \App\Support\RecipeView::data($recipe) + ['initialServings' => $servings, 'rating' => $recipe->rating];
    $cost = $recipe->cost($servings);
    $nutrition = $recipe->nutrition($servings);
    $portion = $recipe->gramsPerServing();
    $cover = $recipe->coverPhoto();
    $photos = $recipe->attachments->where('kind', 'photo');
    $usedIn = $recipe->usedAsIngredient->pluck('recipe')->filter()->unique('id');
    $portionsText = fn (int $n) => $n.' '.($n === 1 ? 'porción' : 'porciones');
@endphp
<script type="application/json" id="recipe-data">{!! json_encode($view, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>

<div x-data="recipeView(JSON.parse(document.getElementById('recipe-data').textContent))"
     @keydown.window="onKey($event)" @visibilitychange.document="onVisible()">

<a href="{{ route('recipes.index') }}" class="link text-sm">← Recetas</a>

{{-- Encabezado: portada, nombre, calificación y lo que se hace con la receta --}}
<header @class(['card mt-2 mb-6 overflow-hidden p-0', 'grid grid-cols-1 sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]' => $cover])>
    @if ($cover)
        <a href="{{ $cover->url() }}" target="_blank" rel="noopener" class="block bg-slate-100">
            <img src="{{ $cover->url() }}" alt="{{ $recipe->name }}" class="aspect-[4/3] size-full object-cover sm:aspect-auto sm:max-h-80 sm:min-h-56">
        </a>
    @endif
    <div class="flex flex-col gap-3 p-5 sm:p-6">
        <div>
            @if ($recipe->category)<div class="eyebrow" data-nav="cocina">{{ $recipe->category }}</div>@endif
            <h1 class="text-3xl font-bold text-slate-900 sm:text-4xl">{{ $recipe->name }}</h1>
        </div>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <div class="-ml-1.5 flex items-center" role="group" aria-label="Calificación">
                @for ($i = 1; $i <= 5; $i++)
                    <button type="button" class="grid size-10 place-items-center rounded-full transition hover:bg-slate-100 disabled:opacity-60" @click="rate({{ $i }})" :disabled="ratingBusy"
                            :class="rating >= {{ $i }} ? 'text-amber-500' : 'text-slate-300'" :aria-pressed="rating === {{ $i }}"
                            aria-label="Calificar con {{ $i }} de 5" title="{{ $i }} de 5 (otro toque la quita)">
                        <x-icon name="star" class="size-6" ::fill="rating >= {{ $i }} ? 'currentColor' : 'none'" :fill="$recipe->rating >= $i ? 'currentColor' : 'none'" />
                    </button>
                @endfor
            </div>
            <span x-show="ratingError" x-cloak class="text-xs text-red-600" x-text="ratingError"></span>
            @foreach ($recipe->tags as $tag)
                <a href="{{ route('recipes.index', ['tag' => $tag->name]) }}" class="badge badge-cheap">{{ $tag->name }}</a>
            @endforeach
        </div>

        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-500">
            <span>Rinde {{ $portionsText($recipe->servings) }}@if ($portion) de unos {{ number_format($portion) }} g en crudo @endif</span>
            @if ($recipe->yieldLabel())<span>· {{ $recipe->yieldLabel() }} en total</span>@endif
            @if ($recipe->minutes)<span class="inline-flex items-center gap-1">· <x-icon name="timer" class="size-4" /> {{ $recipe->minutes }} min</span>@endif
            @if ($recipe->source_url)<span>· <a href="{{ $recipe->source_url }}" target="_blank" rel="noopener" class="link">Receta original</a></span>@endif
        </p>
        @if ($usedIn->isNotEmpty())
            <p class="text-sm text-slate-500">
                Se usa como ingrediente en
                @foreach ($usedIn as $parent)<a class="link" href="{{ route('recipes.show', $parent) }}">{{ $parent->name }}</a>@if (! $loop->last), @endif @endforeach
            </p>
        @endif

        <div class="mt-auto flex flex-wrap gap-2 pt-1">
            @if ($recipe->steps->isNotEmpty())
                <button type="button" class="btn btn-primary px-5 py-2.5 text-base" @click="startCooking(0)">
                    <x-icon name="flame" class="size-5" /> Cocinar
                </button>
                <button type="button" x-show="resume !== null" x-cloak class="btn btn-secondary" @click="startCooking(resume)">
                    Seguir en el paso <span x-text="resume + 1"></span>
                </button>
            @endif
            <a href="{{ route('recipes.edit', $recipe) }}" class="btn btn-secondary">Editar</a>
        </div>
    </div>
</header>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="min-w-0 space-y-6 lg:col-span-2">
        {{-- Ingredientes: las cantidades y costos cambian al instante con las porciones --}}
        <section class="card p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-5 pb-3">
                <h2 class="card-title">Ingredientes</h2>
                <form method="GET" class="flex items-center gap-2 text-sm" @submit.prevent>
                    <label for="servings" class="text-slate-600">Porciones</label>
                    <div class="flex items-center rounded-full bg-surface ring-1 ring-slate-300">
                        <button type="button" class="grid size-10 place-items-center rounded-full text-slate-600 hover:bg-slate-100 disabled:opacity-40" @click="set(-1)" :disabled="servingsCount <= 1" aria-label="Una porción menos">
                            <x-icon name="minus" class="size-4" />
                        </button>
                        <input id="servings" name="servings" type="number" inputmode="numeric" min="1" max="999" value="{{ $servings }}" x-model.number="portions"
                               class="w-12 border-0 bg-transparent p-0 text-center text-base font-semibold tabular-nums outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none">
                        <button type="button" class="grid size-10 place-items-center rounded-full text-slate-600 hover:bg-slate-100" @click="set(1)" aria-label="Una porción más">
                            <x-icon name="plus" class="size-4" />
                        </button>
                    </div>
                    <button type="button" x-show="servingsCount !== servings" x-cloak class="btn btn-ghost btn-sm" @click="portions = servings">Original ({{ $recipe->servings }})</button>
                    <noscript><button class="btn btn-secondary btn-sm">Recalcular</button></noscript>
                </form>
            </div>

            @forelse ($recipe->ingredientSections() as $s => $group)
                @if ($group['section'])
                    <h3 class="border-t border-slate-100 bg-slate-50 px-5 py-2 text-xs font-semibold tracking-wide text-slate-600 uppercase">{{ $group['section'] }}</h3>
                @endif
                <ul class="divide-y divide-slate-100 border-t border-slate-100">
                    @foreach ($group['ingredients'] as $i => $ingredient)
                        @php($item = "sections[{$s}].items[{$i}]")
                        @php($ingredientCost = $ingredient->cost($factor, [$recipe->id]))
                        <li class="flex items-start gap-3 px-5 py-2.5">
                            <span class="w-24 shrink-0 pt-px text-right text-sm font-semibold whitespace-nowrap text-slate-900 tabular-nums sm:w-28" x-text="amount({{ $item }})">{{ $ingredient->quantityLabel($factor) }}</span>
                            <div class="min-w-0 flex-1 text-sm">
                                @if ($ingredient->product)
                                    <a href="{{ route('products.show', $ingredient->product) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $ingredient->product->name }}</a>{{ $ingredient->note ? ', ' : '' }}
                                @elseif ($ingredient->subrecipe)
                                    <a href="{{ route('recipes.show', $ingredient->subrecipe) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $ingredient->subrecipe->name }}</a>{{ $ingredient->note ? ', ' : '' }}
                                @else
                                    <span class="font-medium text-slate-900">{{ $ingredient->name }}</span>{{ $ingredient->note ? ', ' : '' }}
                                @endif
                                @if ($ingredient->note)<span class="text-slate-500">{{ $ingredient->note }}</span>@endif
                                @if ($ingredient->subrecipe)<span class="badge badge-muted ml-1">receta</span>@endif
                                @if ($ingredient->product?->inPantry())<span class="badge badge-cheap ml-1" title="En tu despensa">en casa</span>@endif
                            </div>
                            <div class="shrink-0 text-right text-sm tabular-nums">
                                @if ($ingredientCost !== null)
                                    <span x-text="money(itemCost({{ $item }}))">{{ money($ingredientCost) }}</span>
                                    @if ($view['sections'][$s]['items'][$i]['source'])<div class="hidden text-xs text-slate-500 sm:block">{{ $view['sections'][$s]['items'][$i]['source'] }}</div>@endif
                                @elseif ($ingredient->product || $ingredient->subrecipe)
                                    <span class="text-xs text-amber-700">Sin precio</span>
                                @else
                                    <span class="text-xs text-slate-400">Sin artículo</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @empty
                <p class="border-t border-slate-100 px-5 py-8 text-center text-sm text-slate-500">Esta receta no tiene ingredientes. <a class="link" href="{{ route('recipes.edit', $recipe) }}">Agrégalos</a>.</p>
            @endforelse
        </section>

        {{-- Preparación, por etapa --}}
        <section class="card">
            <div class="flex items-center justify-between gap-2">
                <h2 class="card-title">Preparación</h2>
                @if ($recipe->steps->isNotEmpty())
                    <button type="button" class="btn btn-ghost btn-sm" @click="startCooking(0)"><x-icon name="flame" class="size-4" /> Modo cocinar</button>
                @endif
            </div>
            @php($number = 0)
            @forelse ($recipe->stepStages() as $group)
                @if ($group['stage'])
                    <h3 class="mt-5 mb-1 font-display text-lg font-semibold text-slate-900">{{ $group['stage'] }}</h3>
                @endif
                <ol class="mt-2 space-y-3">
                    @foreach ($group['steps'] as $step)
                        @php($number++)
                        <li class="flex gap-3 text-[15px] leading-relaxed text-slate-700">
                            <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">{{ $number }}</span>
                            <div class="min-w-0">
                                {{ $step->body }}
                                @if ($step->minutes)
                                    <span class="ml-1 inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 align-middle text-xs font-medium text-amber-800 ring-1 ring-amber-200"><x-icon name="timer" class="size-3.5" />{{ $step->minutes }} min</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @empty
                <p class="mt-2 text-sm text-slate-500">Sin pasos escritos. <a class="link" href="{{ route('recipes.edit', $recipe) }}">Agrégalos</a> para cocinar paso a paso.</p>
            @endforelse
        </section>

        @if ($recipe->notes)
            <section class="card">
                <h2 class="card-title">Notas</h2>
                <p class="mt-2 text-sm leading-relaxed whitespace-pre-line text-slate-700">{{ $recipe->notes }}</p>
            </section>
        @endif

        @if ($photos->count() > 1 || $recipe->attachments->where('kind', 'document')->isNotEmpty())
            @include('attachments._gallery', ['owner' => $recipe, 'edit' => route('recipes.edit', $recipe).'#fotos'])
        @endif
    </div>

    <aside class="space-y-6">
        <div class="card">
            <div class="stat-label">Costo para <span x-text="portionsText()">{{ $portionsText($servings) }}</span></div>
            <div class="stat-value" x-text="total === null ? '—' : money(total)">{{ money($cost) }}</div>
            <div class="mt-1 text-xs text-slate-500">
                @if ($cost !== null)
                    <span x-text="money(perServing)">{{ money($recipe->costAt(1) / max(1, $recipe->servings)) }}</span> por porción · lo que se usa, al precio más barato por unidad
                @else
                    Liga ingredientes a artículos con precio para ver el costo.
                @endif
            </div>
            @if ($view['uncosted'] && $cost !== null)
                <div class="mt-2 text-xs text-amber-700">{{ $view['uncosted'] }} {{ $view['uncosted'] === 1 ? 'ingrediente no entra' : 'ingredientes no entran' }} en el costo (sin artículo, sin cantidad o sin precio).</div>
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
                    Toda la receta (<span x-text="servingsCount">{{ $servings }}</span> porc.):
                    <span x-text="fmt(macro('kcal', false))">{{ number_format($nutrition['total']['kcal']) }}</span> kcal ·
                    P <span x-text="fmt(macro('protein', false), 1)">{{ num($nutrition['total']['protein']) }}</span> g ·
                    C <span x-text="fmt(macro('carbs', false), 1)">{{ num($nutrition['total']['carbs']) }}</span> g ·
                    G <span x-text="fmt(macro('fat', false), 1)">{{ num($nutrition['total']['fat']) }}</span> g
                </div>
                @if ($nutrition['missing'])
                    <div class="mt-2 text-xs text-amber-700">{{ $nutrition['missing'] }} {{ $nutrition['missing'] === 1 ? 'artículo no tiene' : 'artículos no tienen' }} datos de nutrición; agrégalos en su ficha.</div>
                @endif
            @else
                <p class="mt-2 text-sm text-slate-500">Agrega la nutrición de los artículos (kcal, proteína, carbohidratos y grasa) para ver los macros. Se llenan solos al buscar su código de barras.</p>
            @endif
        </div>

        <form method="POST" action="{{ route('menu.store') }}" class="card space-y-3" x-data="{ sending: false }" @submit="sending = true">
            @csrf
            <h2 class="card-title">Agregar al menú</h2>
            <input type="hidden" name="recipe_id" value="{{ $recipe->id }}">
            <input type="hidden" name="servings" value="{{ $servings }}" :value="servingsCount">
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
            <button class="btn btn-secondary w-full" :disabled="sending">
                <span x-text="sending ? 'Agregando…' : 'Agregar (' + portionsText() + ')'">Agregar ({{ $portionsText($servings) }})</span>
            </button>
        </form>

        <div class="card space-y-3">
            <h2 class="card-title">Mandar a una lista de compras</h2>
            <p class="text-xs text-slate-500">Revisa lo que hace falta para <span x-text="portionsText()">{{ $portionsText($servings) }}</span>: lo que ya está en tu despensa no se manda.</p>
            <a href="{{ route('recipes.shop.review', [$recipe, 'servings' => $servings]) }}" :href="shopUrl()" class="btn btn-primary w-full">Revisar y mandar</a>
        </div>

        @if ($photos->count() <= 1 && $recipe->attachments->where('kind', 'document')->isEmpty())
            <a href="{{ route('recipes.edit', $recipe) }}#fotos" class="card flex items-center gap-3 text-sm text-slate-600">
                <x-icon name="image" class="size-5 text-slate-400" />
                {{ $cover ? 'Agregar más fotos o documentos' : 'Agregar una foto de portada' }}
            </a>
        @endif
    </aside>
</div>

{{-- Modo cocinar: un paso a la vez, letra grande, botones grandes y la pantalla encendida --}}
@if ($recipe->steps->isNotEmpty())
<div x-show="cooking" x-cloak x-transition.opacity class="fixed inset-0 z-[60] flex flex-col bg-slate-50" data-nav="cocina"
     role="dialog" aria-modal="true" aria-label="Modo cocinar: {{ $recipe->name }}"
     @touchstart.passive="touchStart($event)" @touchend="touchEnd($event)">
    <div class="flex items-center gap-2 border-b border-slate-200 bg-surface px-3 py-2 pt-[max(0.5rem,env(safe-area-inset-top))]">
        <button type="button" class="grid size-12 shrink-0 place-items-center rounded-full text-slate-600 hover:bg-slate-100" @click="stopCooking()" aria-label="Salir del modo cocinar">
            <x-icon name="x" class="size-6" />
        </button>
        <div class="min-w-0 flex-1">
            <div class="truncate font-display text-lg font-semibold text-slate-900">{{ $recipe->name }}</div>
            <div class="text-sm text-slate-500">Paso <span x-text="step + 1"></span> de <span x-text="steps.length"></span> · <span x-text="portionsText()"></span></div>
        </div>
        <button type="button" class="btn btn-secondary h-12 shrink-0 px-4 text-base" @click="panel = !panel" :aria-expanded="panel" aria-label="Ingredientes" title="Ingredientes (tecla I)">
            <x-icon name="list-ordered" class="size-5" /> <span class="hidden sm:inline">Ingredientes</span>
        </button>
    </div>
    <div class="h-1.5 bg-slate-200"><div class="h-full bg-brand transition-all duration-300" :style="{ width: progress + '%' }"></div></div>

    {{-- Temporizadores de otros pasos: siguen corriendo --}}
    <div x-show="activeTimers.some((t) => t.index !== step)" class="flex flex-wrap gap-2 px-4 pt-3">
        <template x-for="t in activeTimers.filter((t) => t.index !== step)" :key="t.index">
            <button type="button" class="flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold tabular-nums ring-1"
                    :class="t.done ? 'animate-pulse bg-red-100 text-red-800 ring-red-300' : 'bg-amber-50 text-amber-900 ring-amber-200'"
                    @click="step = t.index">
                <x-icon name="timer" class="size-4" />
                <span x-text="'Paso ' + (t.index + 1) + ' · ' + (t.done ? '¡Listo!' : clock(t.index))"></span>
            </button>
        </template>
    </div>

    <main class="flex flex-1 flex-col overflow-y-auto">
        <div class="mx-auto my-auto w-full max-w-3xl px-5 py-8 sm:px-8" x-show="current">
            <div class="eyebrow mb-3" x-show="current?.stage" x-text="current?.stage"></div>
            <p class="text-2xl leading-snug font-medium text-slate-900 sm:text-[2rem] sm:leading-snug lg:text-4xl lg:leading-snug" x-text="current?.body"></p>

            {{-- Temporizador del paso --}}
            <template x-if="current && (current.minutes || current.suggested)">
                <div class="mt-8">
                    <template x-if="!timer(step)">
                        <button type="button" class="btn h-16 px-6 text-lg" :class="current.minutes ? 'btn-primary' : 'btn-secondary'"
                                @click="startTimer(step, current.minutes || current.suggested)">
                            <x-icon name="timer" class="size-6" />
                            <span x-text="(current.minutes ? 'Iniciar ' : 'Temporizador de ') + (current.minutes || current.suggested) + ' min'"></span>
                        </button>
                    </template>
                    <template x-if="timer(step)">
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="font-display text-6xl font-semibold tabular-nums sm:text-7xl" :class="timer(step).done ? 'animate-pulse text-red-600' : 'text-slate-900'"
                                 x-text="timer(step).done ? '¡Listo!' : clock(step)" role="timer" aria-live="polite"></div>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" x-show="timer(step).running" class="btn btn-secondary h-14 px-5 text-base" @click="pauseTimer(step)"><x-icon name="pause" class="size-5" /> Pausar</button>
                                <button type="button" x-show="!timer(step).running && !timer(step).done" class="btn btn-primary h-14 px-5 text-base" @click="startTimer(step, 0)"><x-icon name="play" class="size-5" /> Seguir</button>
                                <button type="button" class="btn btn-secondary h-14 px-5 text-base" @click="addMinute(step)">+1 min</button>
                                <button type="button" class="btn btn-ghost h-14 px-5 text-base" @click="resetTimer(step)" x-text="timer(step).done ? 'Apagar' : 'Reiniciar'"></button>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </main>

    <div class="grid grid-cols-2 gap-3 border-t border-slate-200 bg-surface p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
        <button type="button" class="btn btn-secondary h-16 text-lg" @click="go(-1)" :disabled="step === 0">
            <x-icon name="chevron-left" class="size-6" /> Anterior
        </button>
        <button type="button" class="btn btn-primary h-16 text-lg" @click="go(1)">
            <span x-text="step + 1 < steps.length ? 'Siguiente' : 'Terminar'"></span> <x-icon name="chevron-right" class="size-6" />
        </button>
    </div>

    {{-- Ingredientes a la mano, ya escalados --}}
    <div x-show="panel" x-cloak class="fixed inset-0 z-10 bg-black/40" @click="panel = false" aria-hidden="true"></div>
    <aside x-show="panel" x-cloak x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-6 opacity-0 sm:translate-x-6 sm:translate-y-0"
           class="fixed inset-x-0 bottom-0 z-20 flex max-h-[80vh] flex-col rounded-t-3xl bg-surface shadow-2xl sm:inset-y-0 sm:right-0 sm:left-auto sm:max-h-none sm:w-[26rem] sm:rounded-none"
           aria-label="Ingredientes">
        <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
            <div>
                <div class="font-display text-lg font-semibold text-slate-900">Ingredientes</div>
                <div class="text-xs text-slate-500">Para <span x-text="portionsText()"></span> · toca para tachar</div>
            </div>
            <button type="button" class="grid size-12 place-items-center rounded-full text-slate-600 hover:bg-slate-100" @click="panel = false" aria-label="Cerrar ingredientes">
                <x-icon name="x" class="size-6" />
            </button>
        </div>
        <div class="overflow-y-auto pb-[env(safe-area-inset-bottom)]">
            <template x-for="section in sections" :key="section.name ?? '_'">
                <div>
                    <h3 x-show="section.name" class="bg-slate-50 px-5 py-2 text-xs font-semibold tracking-wide text-slate-600 uppercase" x-text="section.name"></h3>
                    <ul class="divide-y divide-slate-100">
                        <template x-for="item in section.items" :key="item.id">
                            <li>
                                <button type="button" class="flex w-full items-start gap-3 px-5 py-3.5 text-left text-base" @click="ticked[item.id] = !ticked[item.id]"
                                        :class="ticked[item.id] && 'opacity-45 line-through'">
                                    <span class="w-24 shrink-0 text-right font-semibold tabular-nums" x-text="amount(item)"></span>
                                    <span class="min-w-0"><span x-text="item.name"></span><span class="text-slate-500" x-show="item.note" x-text="', ' + item.note"></span></span>
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>
            </template>
        </div>
    </aside>
</div>
@endif
</div>
@endsection
