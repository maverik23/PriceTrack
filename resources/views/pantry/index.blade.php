@extends('layouts.app')
@section('title', 'Despensa')

@section('content')
<script type="application/json" id="pantry-data">{!! json_encode(['rows' => $rows, 'lists' => $lists], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>

<div x-data="pantryList(JSON.parse(document.getElementById('pantry-data').textContent))">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Despensa</h1>
            <p class="max-w-2xl text-sm text-slate-500">
                Lo que ya tienes en casa no se manda a la lista cuando planeas recetas. Márcalo sin cantidad si tienes suficiente
                (sal, aceite, especias) o escribe cuánto queda para comprar solo lo que falte.
                Cuando compras algo que se había acabado, vuelve a quedar en casa solo.
            </p>
        </div>
        <a href="{{ route('menu.index') }}" class="btn btn-secondary"><x-icon name="calendar-days" class="size-4" /> Menú de la semana</a>
    </div>

    {{-- Lo que vale lo que hay en casa (de lo que se ve con los filtros) --}}
    <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="card">
            <div class="stat-label">Valor de la despensa</div>
            <div class="stat-value"><span x-show="value.estimated">~</span><span x-text="money(value.total)">$0.00</span></div>
            <div class="mt-1 text-xs text-slate-500">
                Lo que queda, al precio por unidad más barato.
                <span x-show="value.estimated">«~»: lo que tiene «suficiente» cuenta como una presentación.</span>
                <span x-show="value.unpriced"><span x-text="value.unpriced"></span> sin precio no cuentan.</span>
            </div>
        </div>
        <div class="card">
            <div class="stat-label">En casa</div>
            <div class="stat-value" x-text="count('stocked')">{{ $counts['stocked'] }}</div>
            <div class="mt-1 text-xs text-slate-500">artículos que tienes</div>
        </div>
        <div class="card">
            <div class="stat-label">Se acabó</div>
            <div class="stat-value" :class="count('out') && 'text-amber-700'" x-text="count('out')">{{ $counts['out'] }}</div>
            <div class="mt-1 text-xs text-slate-500">
                <span x-show="rows.some((r) => r.pantry === 'out' && !r.in_list)">Algunos aún no están en una lista: <button type="button" class="link" @click="show = 'out'">verlos</button>.</span>
                <span x-show="!rows.some((r) => r.pantry === 'out' && !r.in_list)">Todo lo que se acabó ya está en una lista.</span>
            </div>
        </div>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
        <div class="relative w-full sm:max-w-xs">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
            <input type="search" x-model="q" class="input pl-9" placeholder="Buscar artículo…" aria-label="Buscar artículo" autofocus>
        </div>
        <div class="flex flex-wrap gap-2" role="group" aria-label="Mostrar">
            <button type="button" class="chip" :class="show === 'all' && 'is-active'" @click="show = 'all'">Todo <span class="opacity-70" x-text="rows.length"></span></button>
            <button type="button" class="chip" :class="show === 'stocked' && 'is-active'" @click="show = 'stocked'">En casa <span class="opacity-70" x-text="count('stocked')"></span></button>
            <button type="button" class="chip" :class="show === 'out' && 'is-active'" @click="show = 'out'">Se acabó <span class="opacity-70" x-text="count('out')"></span></button>
        </div>
        @if ($categories->isNotEmpty())
            <select x-model="category" class="input sm:max-w-52" aria-label="Categoría">
                <option value="">Todas las categorías</option>
                @foreach ($categories as $category)
                    <option value="{{ $category }}">{{ $category }}</option>
                @endforeach
            </select>
        @endif
    </div>

    @if ($rows->isEmpty())
        <div class="card py-12 text-center text-slate-500">
            Aún no hay artículos. <a class="link" href="{{ route('products.create') }}">Crea el primero</a> y márcalo aquí cuando lo tengas en casa.
        </div>
    @else
        <div id="pantry-groups" class="scroll-mt-20 space-y-5">
            <template x-for="group in groups" :key="group.name">
                <section class="card p-0">
                    <h2 class="card-title border-b border-slate-100 px-4 py-3" x-text="group.name"></h2>
                    <ul class="divide-y divide-slate-100">
                        <template x-for="row in group.rows" :key="row.id">
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3">
                                <button type="button" class="switch" role="switch" :aria-checked="row.pantry === 'stocked'" :disabled="!!busy[row.id]"
                                        :aria-label="(row.pantry === 'stocked' ? 'Quitar de la despensa: ' : 'Lo tengo en casa: ') + row.name" @click="toggle(row)"></button>
                                <div class="min-w-0 flex-1">
                                    <a :href="row.url" class="font-medium text-slate-900 hover:text-emerald-700" x-text="row.name"></a>
                                    <div class="text-xs text-slate-500">
                                        <span x-text="[row.presentation, row.brand].filter(Boolean).join(' · ')"></span>
                                        <span x-show="row.pantry === 'out'" class="text-amber-700"> · se acabó</span>
                                        <span x-show="saved[row.id]" x-transition.opacity class="text-emerald-700"> · guardado</span>
                                    </div>
                                    <p x-show="errors[row.id]" class="text-xs text-red-600" x-text="errors[row.id]"></p>
                                </div>
                                {{-- Cuánto queda: vacío = suficiente --}}
                                <label x-show="row.pantry === 'stocked'" class="flex w-full items-center gap-2 pl-15 text-xs text-slate-500 sm:w-auto sm:pl-0">
                                    Queda
                                    <input type="text" class="input w-28 py-1.5" :value="row.qty" :placeholder="'suficiente'"
                                           :title="'Cuánto queda (300 g, 1.5 ' + row.unit + '); vacío = suficiente'"
                                           @change="setQty(row, $event.target.value.trim())" @keydown.enter.prevent="$event.target.blur()">
                                </label>
                                <span x-show="row.pantry === 'stocked' && row.value !== null" class="w-20 text-right text-sm text-slate-600 tabular-nums"
                                      :title="row.estimated ? 'Estimado: una presentación al precio más barato' : 'Lo que queda al precio más barato'"
                                      x-text="(row.estimated ? '~' : '') + money(row.value)"></span>
                                @include('pantry._to-list')
                            </li>
                        </template>
                    </ul>
                </section>
            </template>
            <p x-show="!filtered.length" class="card py-10 text-center text-sm text-slate-500">
                Nada coincide. <button type="button" class="link" @click="q = ''; show = 'all'; category = ''">Ver todo</button>
            </p>

            {{-- Paginación en el navegador (mismo aspecto que vendor/pagination/tailwind) --}}
            <nav x-show="filtered.length > perPageOptions[0]" x-cloak aria-label="Paginación" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <p class="text-slate-500" x-text="`${(currentPage - 1) * perPage + 1}–${(currentPage - 1) * perPage + pageRows.length} de ${filtered.length}`"></p>
                    <div class="flex items-center gap-1" role="group" aria-label="Renglones por página">
                        <span class="text-xs text-slate-500">Por página</span>
                        <template x-for="option in perPageOptions" :key="option">
                            <button type="button" class="btn btn-sm min-w-8" :class="option === perPage ? 'bg-brand text-on-brand' : 'btn-ghost'"
                                    :aria-pressed="option === perPage" @click="perPage = option" x-text="option"></button>
                        </template>
                    </div>
                </div>
                <div x-show="pages > 1" class="flex flex-wrap items-center gap-1">
                    <button type="button" class="btn btn-secondary btn-sm" :class="currentPage === 1 && 'opacity-40'" :disabled="currentPage === 1" @click="goTo(currentPage - 1)">← Anterior</button>
                    <span class="px-2 text-slate-500" x-text="`Página ${currentPage} de ${pages}`"></span>
                    <button type="button" class="btn btn-secondary btn-sm" :class="currentPage === pages && 'opacity-40'" :disabled="currentPage === pages" @click="goTo(currentPage + 1)">Siguiente →</button>
                </div>
            </nav>
        </div>
    @endif
</div>
@endsection
