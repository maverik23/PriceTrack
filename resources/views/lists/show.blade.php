@extends('layouts.app')
@section('title', $list->name)

@section('content')
<script type="application/json" id="list-grid-data">@json($grid)</script>

<div x-data="listGrid(JSON.parse(document.getElementById('list-grid-data').textContent))"
     @scroll.window="open && place()" @resize.window="open && place()">

    <a href="{{ route('lists.index') }}" class="link text-sm">← Listas</a>
    <div class="mt-2 mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $list->name }}</h1>
            <p class="text-sm text-slate-500">{{ ucfirst($list->shopped_on->translatedFormat('l d \d\e F Y')) }} @if ($list->notes) · {{ $list->notes }} @endif</p>
        </div>
        <a href="{{ route('lists.edit', $list) }}" class="btn btn-secondary">Editar lista</a>
    </div>

    {{-- Resumen (se recalcula en vivo) --}}
    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="card">
            <div class="stat-label">Total comprado</div>
            <div class="stat-value" x-text="money(totalPurchased)">{{ money($list->total()) }}</div>
            <div class="mt-1 text-sm text-slate-500"><span x-text="purchasedCount"></span> de <span x-text="rows.length"></span> artículos</div>
        </div>
        <div class="card">
            <div class="stat-label">Estimado total</div>
            <div class="stat-value" x-text="money(totalEstimated)"></div>
            <div class="mt-1 text-sm text-slate-500">pendientes al más barato por unidad</div>
        </div>
        <div class="card">
            <div class="stat-label">Ahorro vs. el más caro</div>
            <div class="stat-value text-emerald-700" x-text="money(savings)"></div>
            <div class="mt-1 text-sm text-slate-500">mismo contenido, por unidad</div>
        </div>
        <div class="card">
            <div class="stat-label">Progreso</div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                <div class="h-full rounded-full bg-emerald-500 transition-all" :style="`width: ${progress}%`"></div>
            </div>
            <div class="mt-2 text-sm text-slate-500"><span x-text="progress"></span>% comprado</div>
        </div>
    </div>

    {{-- Hoja --}}
    <div class="card p-0">
        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 border-b border-slate-200 px-4 py-3 text-xs text-slate-500">
            <p>
                Los precios se guardan con fecha <strong class="text-slate-700" x-text="priceDateLabel"></strong>.
                Escribe y usa <kbd class="rounded bg-slate-100 px-1">Tab</kbd> / <kbd class="rounded bg-slate-100 px-1">Enter</kbd> / <kbd class="rounded bg-slate-100 px-1">↑↓</kbd>.
                <kbd class="rounded bg-slate-100 px-1">Enter</kbd> sobre un precio anterior lo confirma para esta fecha; borrarlo deshace lo capturado hoy.
            </p>
            <p class="flex items-center gap-3 whitespace-nowrap">
                <span class="flex items-center gap-1"><span class="size-3 rounded-full bg-emerald-600"></span> comprado aquí</span>
                <span class="font-semibold text-emerald-700">★ más barato</span>
                <span class="text-slate-400">gris = precio anterior</span>
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="sheet w-full border-collapse text-sm" :style="`min-width: ${400 + providers.length * 170}px`">
                <thead class="bg-slate-50 text-xs text-slate-500">
                <tr>
                    <th class="sticky left-0 z-10 bg-slate-50 px-3 py-2 text-left font-semibold">Artículo</th>
                    <th class="w-20 px-2 py-2 text-right font-semibold">Cant.</th>
                    <template x-for="p in providers" :key="p.id">
                        <th class="min-w-40 px-2 py-2 text-right font-semibold text-slate-700" x-text="p.name"></th>
                    </template>
                    <th class="w-32 px-2 py-2 text-right font-semibold">Más barato</th>
                    <th class="w-28 px-2 py-2 text-right font-semibold">Subtotal</th>
                    <th class="w-8"></th>
                </tr>
                </thead>

                <tbody>
                <template x-for="(row, ri) in rows" :key="row.id">
                    <tr class="group align-top">
                        <td class="sticky left-0 z-10 max-w-56 min-w-44 bg-white px-3 py-2 group-hover:bg-slate-50">
                            <div class="flex items-start gap-2">
                                <span class="mt-0.5 grid size-4 shrink-0 place-items-center rounded-full text-[10px]"
                                      :class="row.selected ? 'bg-emerald-600 text-white' : 'border border-slate-300'"
                                      x-text="row.selected ? '✓' : ''"></span>
                                <div class="min-w-0">
                                    <a :href="row.product.url" class="block truncate font-medium text-slate-900 hover:text-emerald-700" x-text="row.product.name"></a>
                                    <div class="truncate text-xs text-slate-500" x-text="[row.product.label, row.product.brand].filter(Boolean).join(' · ')"></div>
                                </div>
                            </div>
                        </td>

                        <td class="px-1 py-1 transition-colors" :class="flashed[row.id + ':qty'] && 'bg-emerald-100'">
                            <input type="text" inputmode="decimal" class="cell-input" :data-cell="ri + '-0'"
                                   x-model="qty[row.id]" @focus="$event.target.select()" @change="commitQty(row)"
                                   @keydown="onCellKey($event, ri, 0, row, null)" aria-label="Cantidad">
                        </td>

                        <template x-for="(p, pi) in providers" :key="p.id">
                            <td class="min-w-40 px-1 py-1 transition-colors" :class="cellClass(row, p.id)">
                                <div class="flex items-center gap-1">
                                    <button type="button" tabindex="-1" class="size-4 shrink-0 rounded-full border transition"
                                            :class="isBought(row, p.id) ? 'border-emerald-600 bg-emerald-600' : (row.cells[p.id] ? 'border-slate-300 bg-white hover:border-emerald-500' : 'invisible')"
                                            :title="isBought(row, p.id) ? 'Comprado aquí (clic para desmarcar)' : 'Marcar como comprado aquí'"
                                            @click="toggleBuy(row, p.id)"></button>
                                    <input type="text" inputmode="decimal" placeholder="—" class="cell-input"
                                           :class="isStale(row, p.id) ? 'text-slate-400' : 'font-medium text-slate-900'"
                                           :data-cell="ri + '-' + (pi + 1)" :aria-label="'Precio en ' + p.name"
                                           x-model="drafts[key(row, p.id)].price" @focus="$event.target.select()"
                                           @change="commit(row, p.id)" @keydown="onCellKey($event, ri, pi + 1, row, p.id)">
                                </div>
                                <div class="flex items-center gap-1 pl-5">
                                    <input type="text" tabindex="-1" class="pres-input flex-1" :placeholder="row.product.label"
                                           title="Presentación: 345 ml, 1 L, 6 x 1 L, 2 x 680 g, 30 pza…"
                                           x-model="drafts[key(row, p.id)].pres" @focus="$event.target.select()"
                                           @change="commit(row, p.id)" @keydown.enter.prevent="commit(row, p.id, true)">
                                    <span class="shrink-0 text-[11px] whitespace-nowrap tabular-nums"
                                          :class="isCheapest(row, p.id) ? 'font-semibold text-emerald-700' : 'text-slate-500'"
                                          x-text="(isCheapest(row, p.id) ? '★ ' : '') + unitText(row, p.id)"></span>
                                </div>
                                <div class="flex min-h-3 justify-between gap-1 pl-6 text-[10px] leading-tight">
                                    <span :class="errors[key(row, p.id)] ? 'text-red-600' : 'text-slate-400'" x-text="cellNote(row, p.id)"></span>
                                    <span class="text-red-500" x-text="diffText(row, p.id)"></span>
                                </div>
                            </td>
                        </template>

                        <td class="px-2 py-2 text-right text-xs">
                            <template x-if="cheapest(row)">
                                <div>
                                    <div class="font-semibold text-emerald-700 tabular-nums" x-text="compareText(row, cheapest(row).v)"></div>
                                    <div class="text-slate-500" x-text="providerName(cheapest(row).pid)"></div>
                                </div>
                            </template>
                        </td>
                        <td class="px-2 py-2 text-right tabular-nums">
                            <div x-show="row.selected" class="font-semibold text-slate-900" x-text="money(subtotal(row) ?? 0)"></div>
                            <div x-show="!row.selected && estimated(row) !== null" class="text-slate-400" x-text="'≈ ' + money(estimated(row) ?? 0)"></div>
                        </td>
                        <td class="px-1 py-2 text-center">
                            <button type="button" tabindex="-1" class="rounded px-1 text-lg leading-none text-slate-300 hover:bg-red-50 hover:text-red-600"
                                    title="Quitar de la lista" @click="removeRow(row)">×</button>
                        </td>
                    </tr>
                </template>

                {{-- Agregar artículo: autocompletar --}}
                <tr class="bg-slate-50/50">
                    <td class="sticky left-0 z-10 bg-slate-50 px-3 py-2">
                        <div class="flex gap-1">
                            <input id="grid-search" type="text" class="input py-1.5" placeholder="+ Artículo o código de barras…"
                                   autocomplete="off" x-model="search" :disabled="busy"
                                   @input="onSearchInput()" @focus="search && (open = true, place())"
                                   @keydown="onSearchKey($event)" @blur="setTimeout(() => open = false, 150)"
                                   @barcode-scanned="onScanned()">
                            <x-scan-button for="grid-search" tabindex="-1" class="py-1" />
                        </div>
                    </td>
                    <td :colspan="providers.length + 4" class="px-3 py-2 text-xs text-slate-500">
                        <template x-if="!creating">
                            <span>↑↓ para elegir, <kbd class="rounded bg-slate-100 px-1">Enter</kbd> para agregar. Si no existe, se crea al momento. También puedes escanear o escribir el código de barras.</span>
                        </template>
                        <template x-if="creating">
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <template x-if="!creating.barcode">
                                    <span class="text-slate-600">Nuevo: <strong class="text-slate-900" x-text="creating.name"></strong> — presentación</span>
                                </template>
                                <template x-if="creating.barcode">
                                    <span class="flex items-center gap-2">
                                        <span class="font-mono text-xs text-slate-500" x-text="creating.barcode"></span>
                                        <input id="grid-create-name" type="text" class="input w-64 py-1" placeholder="Nombre del artículo"
                                               x-model="creating.name" :disabled="creating.loading"
                                               @keydown.enter.prevent="createProduct()" @keydown.escape="cancelCreate()">
                                    </span>
                                </template>
                                <input id="grid-create-pres" type="text" class="input w-32 py-1" placeholder="345 ml"
                                       x-model="creating.pres" :disabled="creating.loading" @keydown.enter.prevent="createProduct()"
                                       @keydown.escape="cancelCreate()">
                                <button type="button" class="btn btn-primary btn-sm" :disabled="creating.loading" @click="createProduct()">Crear y agregar</button>
                                <button type="button" class="btn btn-ghost btn-sm" @click="cancelCreate()">Cancelar</button>
                                <span class="text-xs text-slate-500" x-show="creating.loading">Buscando el código en internet…</span>
                                <span class="text-xs text-slate-500" x-show="creating.source"
                                      x-text="[creating.brand, creating.category].filter(Boolean).concat('de ' + creating.source).join(' · ')"></span>
                                <span class="text-xs text-red-600" x-text="creating.error"></span>
                            </div>
                        </template>
                    </td>
                </tr>
                </tbody>

                <tfoot class="bg-slate-50 text-xs" x-show="rows.length">
                <tr>
                    <td colspan="2" class="sticky left-0 z-10 bg-slate-50 px-3 py-2 font-semibold text-slate-600">
                        Todo en un solo lugar
                        <div class="font-normal text-slate-400">cant. × presentación habitual</div>
                    </td>
                    <template x-for="p in providers" :key="p.id">
                        <td class="px-2 py-2 text-right tabular-nums" :class="isBestBasket(p.id) && 'bg-emerald-50'">
                            <div class="text-sm font-semibold" :class="isBestBasket(p.id) ? 'text-emerald-700' : 'text-slate-800'"
                                 x-text="basket(p.id).covered ? (isBestBasket(p.id) ? '★ ' : '') + money(basket(p.id).total) : '—'"></div>
                            <div :class="basket(p.id).covered === rows.length ? 'text-slate-400' : 'text-amber-600'"
                                 x-text="basket(p.id).covered + ' de ' + rows.length + ' artículos'"></div>
                        </td>
                    </template>
                    <td colspan="3"></td>
                </tr>
                <tr>
                    <td colspan="2" class="sticky left-0 z-10 bg-slate-50 px-3 py-2 font-semibold text-slate-600">Comprado aquí</td>
                    <template x-for="p in providers" :key="p.id">
                        <td class="px-2 py-2 text-right text-sm tabular-nums" x-text="boughtAt(p.id) ? money(boughtAt(p.id)) : '—'"></td>
                    </template>
                    <td class="px-2 py-2 text-right text-slate-500">Total</td>
                    <td class="px-2 py-2 text-right text-sm font-semibold text-slate-900 tabular-nums" x-text="money(totalPurchased)"></td>
                    <td></td>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- Sugerencias del autocompletar (fijas para no quedar recortadas por el scroll de la tabla) --}}
    <div x-show="open && options.length" x-cloak :style="dropStyle" @mousedown.prevent
         class="fixed z-50 max-h-80 overflow-auto rounded-lg bg-white py-1 shadow-lg ring-1 ring-slate-200">
        <template x-for="(opt, i) in options" :key="opt.create ? 'new' : opt.id">
            <button type="button" tabindex="-1" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm"
                    :class="i === hi ? 'bg-emerald-50' : 'hover:bg-slate-50'" @mouseenter="hi = i" @click="pick(opt)">
                <span class="min-w-0 truncate" x-show="!opt.create">
                    <span class="font-medium text-slate-900" x-text="opt.name"></span>
                    <span class="text-xs text-slate-500" x-text="[opt.label, opt.brand].filter(Boolean).join(' · ')"></span>
                </span>
                <span class="shrink-0 text-xs text-amber-600" x-show="!opt.create && opt.inList">ya en la lista</span>
                <span class="font-medium text-emerald-700" x-show="opt.create && !opt.barcode">+ Crear «<span x-text="opt.name"></span>»</span>
                <span class="font-medium text-emerald-700" x-show="opt.barcode">+ Buscar el código <span class="font-mono" x-text="opt.barcode"></span> en internet y crear</span>
            </button>
        </template>
    </div>
</div>
@endsection
