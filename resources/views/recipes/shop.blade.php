@extends('layouts.app')
@section('title', 'Mandar a la lista')

{{--
    Revisión antes de mandar ingredientes a una lista (desde una receta o el menú de la semana).
    Variables: $title, $subtitle, $back, $action, $hidden (campos ocultos), $newList, $rows, $unlinked, $lists
--}}
@section('content')
<script type="application/json" id="shop-rows">{!! json_encode($rows, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>

<a href="{{ $back }}" class="link text-sm">← {{ $title }}</a>
<div class="mt-2 mb-6">
    <h1 class="text-3xl font-bold text-slate-900">Mandar a la lista</h1>
    <p class="text-sm text-slate-500">{{ $title }} · {{ $subtitle }}</p>
</div>

@if (empty($rows))
    <div class="card py-10 text-center text-slate-500">
        <p>No hay ingredientes ligados a artículos con cantidad, así que no hay nada que mandar.</p>
        <p class="mt-1 text-sm">Liga cada ingrediente a un artículo del súper al editar la receta.</p>
        @if ($unlinked)
            <p class="mt-3 text-sm">Sin artículo: {{ implode(', ', $unlinked) }}.</p>
        @endif
        <a href="{{ $back }}" class="btn btn-secondary mt-4">Volver</a>
    </div>
@else
<form method="POST" action="{{ $action }}" x-data="{ rows: JSON.parse(document.getElementById('shop-rows').textContent), sending: false,
        get picked() { return this.rows.filter((r) => r.checked) },
        get total() { return this.picked.reduce((s, r) => s + (r.estimate ?? 0), 0) },
        get unpriced() { return this.picked.filter((r) => r.estimate === null).length },
        money(v) { return '$' + Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) } }"
      @submit="sending = true" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    @csrf
    <input type="hidden" name="reviewed" value="1">
    @foreach ($hidden as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach

    <div class="card p-0 lg:col-span-2">
        <div class="px-5 pt-5 pb-3">
            <h2 class="card-title">¿Qué hay que comprar?</h2>
            <p class="text-xs text-slate-500">
                Lo que ya está en tu despensa viene desmarcado. Corrige aquí mismo: desmarca lo que ya tienes y marca lo que se acabó;
                la despensa se actualiza al mandar la lista.
            </p>
        </div>
        <ul class="divide-y divide-slate-100 border-t border-slate-100">
            <template x-for="row in rows" :key="row.id">
                <li>
                    <label class="flex cursor-pointer items-start gap-3 px-5 py-3 transition hover:bg-slate-50" :class="!row.checked && 'opacity-60'">
                        <input type="checkbox" name="buy[]" :value="row.id" x-model="row.checked" class="mt-0.5 size-5 shrink-0 rounded border-slate-300"
                               :aria-label="'Comprar ' + row.name">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-slate-900" x-text="row.name"></span>
                            <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                                <span>Se necesita <span class="tabular-nums" x-text="row.need"></span></span>
                                <span x-show="row.have" class="badge badge-cheap" x-text="row.have === 'suficiente' ? 'En casa: suficiente' : 'En casa: ' + row.have"></span>
                                <span x-show="row.checked && row.buy && row.have">· comprar <span class="tabular-nums" x-text="row.buy"></span></span>
                            </span>
                            <span class="mt-0.5 block text-xs" x-show="row.checked !== (row.buy !== null)">
                                <span class="text-amber-700" x-show="row.checked">Se acabó: se compra todo y se quita de la despensa</span>
                                <span class="text-emerald-700" x-show="!row.checked">Lo tienes: se marca en tu despensa</span>
                            </span>
                        </span>
                        <span class="shrink-0 text-right text-sm whitespace-nowrap tabular-nums">
                            <span x-show="row.checked && row.estimate !== null" x-text="money(row.estimate ?? 0)"></span>
                            <span x-show="row.checked && row.estimate === null" class="text-xs text-amber-700">Sin precio</span>
                            <span x-show="!row.checked" class="text-slate-400">—</span>
                        </span>
                    </label>
                </li>
            </template>
        </ul>
        @if ($unlinked)
            <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                Sin artículo ligado (no se mandan; agrégalos a mano si hacen falta): {{ implode(', ', $unlinked) }}.
            </p>
        @endif
    </div>

    <div class="space-y-4">
        <div class="card">
            <div class="stat-label">Quedará en la lista</div>
            <div class="stat-value" x-text="money(total)"></div>
            <div class="mt-1 text-xs text-slate-500">
                <span x-text="picked.length"></span> de <span x-text="rows.length"></span> artículos, en la tienda más barata para esa cantidad (paquetes completos).
                <span x-show="unpriced" class="text-amber-700"><span x-text="unpriced"></span> sin precio.</span>
            </div>
        </div>
        <div class="card space-y-3">
            <div>
                <label class="label" for="list_id">Lista</label>
                <select id="list_id" name="list_id" class="input">
                    <option value="">Lista nueva «{{ $newList }}»</option>
                    @foreach ($lists as $list)
                        <option value="{{ $list->id }}" @selected((string) old('list_id') === (string) $list->id)>{{ $list->name }} ({{ $list->shopped_on->translatedFormat('d M') }})</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">Se suma a lo que ya tenga la lista.</p>
            </div>
            <button class="btn btn-primary w-full" :disabled="sending">
                <span x-text="sending ? 'Guardando…' : (picked.length ? 'Mandar ' + picked.length + (picked.length === 1 ? ' artículo' : ' artículos') : 'Guardar la despensa')">Mandar a la lista</span>
            </button>
            <p x-show="!picked.length" class="text-xs text-slate-500">Todo está en casa: no se agrega nada. Si algo se acabó, márcalo.</p>
            <a href="{{ route('pantry.index') }}" class="link block text-center text-xs">Abrir la despensa</a>
        </div>
    </div>
</form>
@endif
@endsection
