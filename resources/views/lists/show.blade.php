@extends('layouts.app')
@section('title', $list->name)

@section('content')
<script type="application/json" id="list-grid-data">@json($grid)</script>

<div x-data="listGrid(JSON.parse(document.getElementById('list-grid-data').textContent))"
     @scroll.window="open && place()" @resize.window="open && place()">

    <a href="{{ route('lists.index') }}" class="link text-sm">← Listas</a>
    <div class="mt-2 mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">{{ $list->name }} @if ($list->isDone()) <span class="badge badge-cheap align-middle text-sm">Comprada</span> @endif</h1>
            <p class="text-sm text-slate-500">{{ ucfirst($list->shopped_on->translatedFormat('l d \d\e F Y')) }} @if ($list->notes) · {{ $list->notes }} @endif</p>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
            <span class="text-xs text-slate-500" x-text="fetchStatus"></span>
            <button type="button" class="btn btn-primary" :disabled="fetching" @click="fetchAll()"
                    title="Consulta en línea las tiendas donde el artículo tiene dirección y guarda los precios de hoy">
                <x-icon name="refresh-cw" class="size-4" ::class="fetching && 'animate-spin'" />
                <span x-text="fetching ? 'Consultando…' : 'Obtener precios'">Obtener precios</span>
            </button>
            <form method="POST" action="{{ route('lists.done', $list) }}" data-no-busy>
                @csrf
                <input type="hidden" name="done" value="{{ $list->isDone() ? 0 : 1 }}">
                <button class="btn btn-secondary" title="{{ $list->isDone() ? 'Volver a ponerla entre las pendientes' : 'Ya se compró: se tacha y deja de verse entre las pendientes' }}">
                    <x-icon :name="$list->isDone() ? 'rotate-ccw' : 'check'" class="size-4" />
                    {{ $list->isDone() ? 'Reabrir' : 'Marcar comprada' }}
                </button>
            </form>
            <a href="{{ route('lists.edit', $list) }}" class="btn btn-secondary">Editar lista</a>
        </div>
    </div>

    <x-progress-panel run="run" class="mb-6" />

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
            <div class="mt-1 text-sm text-slate-500">pendientes en la tienda más barata para lo que necesitas</div>
        </div>
        <div class="card">
            <div class="stat-label">Ahorro vs. el más caro</div>
            <div class="stat-value text-emerald-700" x-text="money(realSavings)"></div>
            <div class="mt-1 text-sm text-slate-500" title="Lo que costaba lo que compraste en la tienda más cara, con los paquetes que hay que comprar ahí, menos lo que pagaste">
                real, con paquetes completos
            </div>
        </div>
        <div class="card">
            <div class="stat-label">Total más barato</div>
            <div class="stat-value" x-text="money(cheapestTotal)"></div>
            <div class="mt-1 text-sm text-slate-500">lo que compraste, en la tienda más barata de cada artículo</div>
            <div class="mt-1 text-sm text-slate-500">
                Posible ahorro: <span class="font-semibold tabular-nums" :class="possibleSavings >= 0.01 ? 'text-amber-700' : 'text-emerald-700'" x-text="money(possibleSavings)"></span>
            </div>
        </div>
    </div>

    {{-- Hoja --}}
    <div class="card p-0">
        <div class="flex flex-wrap items-center justify-end gap-x-6 gap-y-1 border-b border-slate-200 px-4 py-2 text-xs text-slate-500">
            <p class="flex flex-wrap items-center gap-3 whitespace-nowrap">
                {{-- Tiendas sin datos en esta lista (u ocultadas a mano): se pueden mostrar --}}
                <label x-show="hiddenProviders.length" class="flex items-center gap-1.5">
                    <x-icon name="store" class="size-3.5 text-slate-400" />
                    <select class="input w-auto py-1 pr-8 text-xs" aria-label="Mostrar una tienda" @change="showProvider($event.target.value); $event.target.value = ''">
                        <option value="" x-text="'+ Mostrar tienda (' + hiddenProviders.length + (hiddenProviders.length === 1 ? ' oculta)' : ' ocultas)')"></option>
                        <template x-for="p in hiddenProviders" :key="p.id">
                            <option :value="p.id" x-text="p.name"></option>
                        </template>
                    </select>
                </label>
                <span x-show="columnsError" class="text-red-600" x-text="columnsError"></span>
                <span class="flex items-center gap-1"><span class="size-3 rounded-full bg-brand"></span> comprado aquí</span>
                <span class="font-semibold text-emerald-700">★ más barato</span>
                <span class="text-slate-400">gris = precio anterior</span>
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="sheet w-full border-collapse text-sm" :style="`min-width: ${290 + visibleProviders.length * 180}px`">
                <thead class="bg-slate-50 text-xs text-slate-500">
                <tr>
                    <th class="sticky left-0 z-10 bg-slate-50 px-3 py-2 text-left font-semibold">Artículo <span class="font-normal text-slate-400">· lo que pides y lo que compraste</span></th>
                    <template x-for="p in visibleProviders" :key="p.id">
                        <th class="group/col min-w-44 px-2 py-2 text-right font-semibold text-slate-700">
                            <span class="inline-flex items-center gap-1">
                                <span x-text="p.name"></span>
                                <button type="button" tabindex="-1" x-show="canHide(p.id)" @click="hideProvider(p.id)"
                                        class="icon-btn size-6 text-slate-300 hover:text-slate-700 sm:opacity-0 sm:group-hover/col:opacity-100 sm:focus:opacity-100"
                                        :title="'Ocultar ' + p.name + ' en esta lista'" :aria-label="'Ocultar ' + p.name"><x-icon name="eye-off" class="size-3.5" /></button>
                            </span>
                            <span class="block font-normal text-slate-400" x-show="boughtCount(p.id)" x-text="boughtCount(p.id) + (boughtCount(p.id) === 1 ? ' comprado' : ' comprados')"></span>
                        </th>
                    </template>
                </tr>
                </thead>

                <tbody>
                <template x-for="(row, ri) in rows" :key="row.id">
                    <tr class="group align-top">
                        {{-- Artículo: lo que pides, lo que compraste (dónde, cuánto y cuánto pagaste) y sus acciones --}}
                        <td class="sticky left-0 z-10 w-56 max-w-56 min-w-52 bg-surface px-3 py-2 group-hover:bg-slate-50 sm:w-72 sm:max-w-72 sm:min-w-64">
                            <div class="flex items-start gap-2">
                                <span class="mt-0.5 grid size-4 shrink-0 place-items-center rounded-full text-[10px]"
                                      :class="row.selected ? 'bg-brand text-on-brand' : 'border border-slate-300'"
                                      :title="row.selected ? 'Comprado' : 'Pendiente: elige la tienda donde lo compraste'"
                                      x-text="row.selected ? '✓' : ''"></span>
                                <div class="min-w-0 flex-1">
                                    <a :href="row.product.url" class="block truncate font-medium text-slate-900 hover:text-emerald-700" :title="row.product.name" x-text="row.product.name"></a>
                                    <div class="truncate text-xs text-slate-500" x-text="[row.product.label, row.product.brand].filter(Boolean).join(' · ')"></div>
                                </div>
                                <div class="-mt-1 -mr-1 flex shrink-0 items-center">
                                    <button type="button" tabindex="-1" x-show="Object.keys(row.links).length" class="icon-btn size-7 text-slate-400 hover:text-emerald-700"
                                            :disabled="rowFetching[row.id]" @click="fetchRow(row)"
                                            title="Traer el precio de hoy de todas las tiendas con dirección" aria-label="Traer precios de todas las tiendas">
                                        <x-icon name="refresh-cw" class="size-3.5" ::class="rowFetching[row.id] && 'animate-spin text-emerald-700'" />
                                    </button>
                                    <button type="button" tabindex="-1" class="icon-btn size-7 text-slate-400 hover:text-red-600"
                                            title="Quitar de la lista" :aria-label="'Quitar ' + row.product.name + ' de la lista'" @click="removeRow(row)">
                                        <x-icon name="trash" class="size-3.5" />
                                    </button>
                                </div>
                            </div>
                            {{-- Cantidad: pedida / comprada · Precio: el más barato para lo pedido / lo pagado --}}
                            <dl class="mt-1.5 grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-2 gap-y-1 pl-6 text-xs">
                                <dt class="text-slate-500"><label :for="'qty-' + row.id">Cantidad</label></dt>
                                <dd class="flex min-w-0 items-center gap-1">
                                    <input type="text" class="cell-input w-16 shrink-0 rounded-md bg-slate-50 px-1.5 py-0.5 ring-1 ring-slate-200 transition-colors"
                                           :class="flashed[row.id + ':qty'] && 'bg-emerald-100'"
                                           :id="'qty-' + row.id" :data-cell="ri + '-0'"
                                           :inputmode="units[row.product.unit].dimension === 'count' ? 'decimal' : 'text'"
                                           :title="'Lo que pides: presentaciones (2) o el contenido (610 g, 1.5 kg). Ahora: ' + needAmount(row)"
                                           x-model="qty[row.id]" @focus="$event.target.select()" @change="commitQty(row)"
                                           @keydown="onCellKey($event, ri, 0, row, null)" aria-label="Cantidad pedida">
                                    <span class="text-slate-300">/</span>
                                    <span x-show="row.selected" class="truncate" :class="boughtShort(row) ? 'font-medium text-amber-700' : 'font-medium text-slate-800'"
                                          :title="'Comprado: ' + boughtText(row) + (boughtShort(row) ? ' (menos de lo pedido)' : '')" x-text="boughtShortText(row)"></span>
                                    <span x-show="!row.selected" class="text-slate-400">sin comprar</span>
                                </dd>
                                <dt class="text-slate-500">Precio</dt>
                                <dd class="flex min-w-0 items-baseline gap-1 tabular-nums">
                                    <span class="text-emerald-700" :title="'El más barato para lo que pides' + (bestBuy(row) ? ': ' + providerName(bestBuy(row).pid) : '')"
                                          x-text="estimated(row) !== null ? money(estimated(row)) : '—'"></span>
                                    <span class="text-slate-300">/</span>
                                    <span x-show="row.selected" class="text-sm font-semibold text-slate-900" title="Lo que pagaste" x-text="money(subtotal(row) ?? 0)"></span>
                                    <span x-show="!row.selected" class="text-slate-400">—</span>
                                </dd>
                            </dl>
                        </td>

                        <template x-for="(p, pi) in visibleProviders" :key="p.id">
                            <td class="min-w-44 px-1 py-1 transition-colors" :class="cellClass(row, p.id)">
                                <div class="flex items-center gap-1">
                                    <button type="button" tabindex="-1" class="size-4 shrink-0 rounded-full border transition"
                                            :class="isBought(row, p.id) ? 'border-brand bg-brand' : (row.cells[p.id] ? 'border-slate-300 bg-surface hover:border-emerald-500' : 'invisible')"
                                            :title="isBought(row, p.id) ? 'Comprado aquí (clic para desmarcar)' : 'Marcar como comprado aquí'"
                                            @click="toggleBuy(row, p.id)"></button>
                                    <a x-show="row.links[p.id]" :href="row.links[p.id]" target="_blank" rel="noopener" tabindex="-1"
                                       class="shrink-0 text-xs text-slate-300 hover:text-emerald-600" title="Abrir el artículo en la tienda">↗</a>
                                    {{-- Traer el precio de hoy de la dirección guardada --}}
                                    <button type="button" x-show="row.links[p.id]" tabindex="-1" @click="fetchOne(row, p.id)" :disabled="!!saving[key(row, p.id)]"
                                            class="shrink-0 text-slate-300 hover:text-emerald-600 disabled:text-emerald-600" :title="'Traer el precio de hoy en ' + p.name"
                                            :aria-label="'Traer el precio de hoy en ' + p.name">
                                        <x-icon name="refresh-cw" class="size-3" ::class="saving[key(row, p.id)] && 'animate-spin'" />
                                    </button>
                                    {{-- Marca que vende esa tienda (cada una puede tener otra) --}}
                                    <span x-show="row.stores[p.id]?.brand" class="max-w-[5.5rem] shrink-0 truncate text-[10px] text-slate-500"
                                          :title="row.stores[p.id]?.name || row.stores[p.id]?.brand" x-text="row.stores[p.id]?.brand"></span>
                                    <input type="text" inputmode="decimal" placeholder="—" class="cell-input"
                                           :class="isStale(row, p.id) ? 'text-slate-400' : 'font-medium text-slate-900'"
                                           :data-cell="ri + '-' + (pi + 1)" :aria-label="'Precio en ' + p.name"
                                           x-model="drafts[key(row, p.id)].price" @focus="$event.target.select()"
                                           @change="commit(row, p.id)" @keydown="onCellKey($event, ri, pi + 1, row, p.id)">
                                </div>
                                <div class="flex items-center gap-1 pl-5">
                                    <input type="text" tabindex="-1" class="pres-input flex-1" :placeholder="row.product.label"
                                           title="Presentación: 345 ml, 1 L, 6 x 1 L, 2 x 680 g, 30 pza… o «por kg» si se vende a granel"
                                           x-model="drafts[key(row, p.id)].pres" @focus="$event.target.select()"
                                           @change="commit(row, p.id)" @keydown.enter.prevent="commit(row, p.id, true)">
                                    <span class="shrink-0 text-[11px] whitespace-nowrap tabular-nums"
                                          :class="isCheapest(row, p.id) ? 'font-semibold text-emerald-700' : 'text-slate-500'"
                                          x-text="(isCheapest(row, p.id) ? '★ ' : '') + unitText(row, p.id)"></span>
                                </div>
                                {{-- Donde se compró: cuánto se llevó (paquetes o, a granel, el contenido) y lo pagado --}}
                                <div x-show="isBought(row, p.id)" class="mt-1 flex items-center gap-1 pl-5 text-[11px]">
                                    <label class="font-medium text-emerald-800" :for="'bought-' + row.id + '-' + p.id">Compré</label>
                                    <input type="text" class="pres-input w-16 text-right" :id="'bought-' + row.id + '-' + p.id"
                                           :inputmode="selectedValue(row)?.bulk ? 'text' : 'decimal'"
                                           :title="selectedValue(row)?.bulk ? 'Cuánto te llevaste: 1.2 kg, 800 g…' : 'Cuántos paquetes te llevaste (vacío = los que cubren lo pedido)'"
                                           :value="boughtDraft(row)" @input="boughtDrafts[row.id] = $event.target.value" @focus="$event.target.select()"
                                           @change="commitBought(row, $event.target.value)" @keydown.enter.prevent="$event.target.blur()"
                                           @keydown.escape="delete boughtDrafts[row.id]; $event.target.value = boughtInput(row); $event.target.blur()">
                                    <span class="text-slate-500" x-text="selectedValue(row)?.bulk ? '' : 'paq.'"></span>
                                    <span class="ml-auto font-semibold text-slate-900 tabular-nums" title="Lo que pagaste" x-text="money(subtotal(row) ?? 0)"></span>
                                </div>
                                <div class="flex min-h-4 flex-wrap items-baseline justify-between gap-x-1 pl-6 text-[10px] leading-tight">
                                    <span>
                                        <span :class="errors[key(row, p.id)] ? 'text-red-600' : 'text-slate-400'" x-text="cellNote(row, p.id)"></span>
                                        <span class="text-red-500" x-text="diffText(row, p.id)"></span>
                                    </span>
                                    {{-- Lo que pagarías aquí por lo que necesitas: paquetes completos o lo justo a granel --}}
                                    <span x-show="!errors[key(row, p.id)] && cellTotal(row, p.id) !== null && !isBought(row, p.id)" class="ml-auto text-[11px] whitespace-nowrap tabular-nums"
                                          :title="totalTitle(row, p.id)">
                                        <span class="text-slate-400" x-text="takeText(row, p.id)"></span>
                                        <span class="font-semibold" :class="isBestBuy(row, p.id) ? 'text-emerald-700' : 'text-slate-700'"
                                              x-text="money(cellTotal(row, p.id) ?? 0)"></span>
                                    </span>
                                </div>
                            </td>
                        </template>
                    </tr>
                </template>

                {{-- Agregar artículo: autocompletar --}}
                <tr class="bg-slate-50/50">
                    <td class="sticky left-0 z-10 bg-slate-50 px-3 py-2">
                        <div class="flex gap-1">
                            <input id="grid-search" type="text" class="input py-1.5" placeholder="+ Artículo o código"
                                   autocomplete="off" x-model="search" :disabled="busy"
                                   @input="onSearchInput()" @focus="search && (open = true, place())"
                                   @keydown="onSearchKey($event)" @blur="setTimeout(() => open = false, 150)"
                                   @barcode-scanned="onScanned()">
                            <x-scan-button for="grid-search" tabindex="-1" class="py-1" />
                        </div>
                    </td>
                    <td :colspan="Math.max(1, visibleProviders.length)" class="px-3 py-2 text-xs text-slate-500">
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
                {{-- Lo que compraste en cada tienda: el ticket, que es lo que va a los gastos --}}
                <tr class="border-t-2 border-slate-200">
                    <td class="sticky left-0 z-10 bg-slate-50 px-3 py-2">
                        <div class="font-semibold text-slate-700">Ticket por tienda <span class="font-normal text-slate-400">· va a los gastos</span></div>
                        <div class="text-slate-400">escribe el total si hubo descuentos</div>
                        <div class="mt-0.5 flex items-baseline justify-between gap-2">
                            <span class="text-slate-500"><span x-text="purchasedCount"></span> de <span x-text="rows.length"></span> artículos comprados</span>
                            <span class="font-display text-base font-semibold text-slate-900 tabular-nums" x-text="money(totalPurchased)"></span>
                        </div>
                    </td>
                    <template x-for="p in visibleProviders" :key="p.id">
                        <td class="px-2 py-2 text-right tabular-nums transition-colors" :class="flashed['ticket:' + p.id] && 'bg-emerald-100'">
                            {{-- Lo que de verdad pagaste ahí (con descuentos); vacío = la suma de lo marcado --}}
                            <div x-show="boughtCount(p.id) || hasTicket(p.id)" class="flex items-center justify-end gap-1">
                                <span class="text-slate-400">$</span>
                                <input type="text" inputmode="decimal" class="cell-input w-24 rounded-md bg-surface px-1.5 py-0.5 text-sm font-semibold ring-1 ring-slate-200"
                                       :class="hasTicket(p.id) ? 'text-slate-900' : 'text-slate-500'"
                                       :placeholder="boughtAt(p.id).toFixed(2)" :aria-label="'Total del ticket de ' + p.name"
                                       title="Total del ticket: lo que de verdad pagaste (con descuentos). Vacío = la suma de lo marcado aquí."
                                       x-model="ticketDrafts[p.id]" @focus="$event.target.select()" @change="commitTicket(p.id)"
                                       @keydown.enter.prevent="$event.target.blur()">
                            </div>
                            <div x-show="!boughtCount(p.id) && !hasTicket(p.id)" class="text-sm text-slate-300">—</div>
                            <div class="mt-0.5 text-slate-500" x-show="boughtCount(p.id)" x-text="boughtCount(p.id) + (boughtCount(p.id) === 1 ? ' artículo' : ' artículos')"></div>
                            <div class="text-[11px]" x-show="hasTicket(p.id) && Math.abs(ticketTotal(p.id) - boughtAt(p.id)) >= 0.01"
                                 :class="ticketTotal(p.id) < boughtAt(p.id) ? 'text-emerald-700' : 'text-amber-700'"
                                 x-text="'marcado ' + money(boughtAt(p.id)) + ' (' + (ticketTotal(p.id) < boughtAt(p.id) ? '−' : '+') + money(Math.abs(ticketTotal(p.id) - boughtAt(p.id))).replace('-', '') + ')'"></div>
                            <div class="text-[11px] text-red-600" x-show="errors['ticket:' + p.id]" x-text="errors['ticket:' + p.id]"></div>
                        </td>
                    </template>
                </tr>
                {{-- Lo que costaría llevarte todo lo que pides en una sola tienda --}}
                <tr>
                    <td class="sticky left-0 z-10 bg-slate-50 px-3 py-2">
                        <div class="font-semibold text-slate-600">Si compras todo aquí</div>
                        <div class="text-slate-400">lo que pides, con los paquetes que vende cada tienda</div>
                    </td>
                    <template x-for="p in visibleProviders" :key="p.id">
                        <td class="px-2 py-2 text-right tabular-nums" :class="isBestBasket(p.id) && 'bg-emerald-50'">
                            <div class="text-sm font-semibold" :class="isBestBasket(p.id) ? 'text-emerald-700' : 'text-slate-700'"
                                 x-text="basket(p.id).covered ? (isBestBasket(p.id) ? '★ ' : '') + money(basket(p.id).total) : '—'"></div>
                            <div :class="basket(p.id).covered === rows.length ? 'text-slate-400' : 'text-amber-600'"
                                 x-text="basket(p.id).covered + ' de ' + rows.length + ' con precio'"></div>
                        </td>
                    </template>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- Sugerencias del autocompletar (fijas para no quedar recortadas por el scroll de la tabla) --}}
    <div x-show="open && options.length" x-cloak :style="dropStyle" @mousedown.prevent
         class="fixed z-50 max-h-80 overflow-auto rounded-lg bg-surface py-1 shadow-lg ring-1 ring-slate-200">
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
