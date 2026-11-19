{{-- Algo se acabó: ¿agregarlo a una lista de compras? (Alpine rowMethods de resources/js/pantry.js, con `row`) --}}
<div x-show="asking[row.id]" x-cloak class="flex w-full flex-wrap items-center gap-2 rounded-xl bg-amber-50 px-3 py-2 text-sm ring-1 ring-amber-200" role="group" aria-label="Agregar a una lista">
    <span class="text-amber-900"><span class="font-semibold">Se acabó.</span> ¿Lo agrego a la lista de compras?</span>
    <select class="input w-auto max-w-56 py-1.5 text-sm" x-model="listChoice" aria-label="Lista de compras">
        <template x-for="l in lists" :key="l.id">
            <option :value="l.id" x-text="l.name" :selected="l.id == listChoice"></option>
        </template>
        <option value="">Una lista nueva</option>
    </select>
    <button type="button" class="btn btn-primary btn-sm" @click="addToList(row)" :disabled="!!busy[row.id]">Agregar</button>
    <button type="button" class="btn btn-ghost btn-sm" @click="dismiss(row)">Ahora no</button>
</div>
<p x-show="added[row.id]" x-cloak class="w-full text-xs text-emerald-700">
    ✓ En la lista <a class="link" :href="added[row.id]?.url" x-text="added[row.id]?.name"></a>
</p>
<p x-show="row.pantry === 'out' && row.in_list && !added[row.id] && !asking[row.id]" x-cloak class="text-xs text-slate-500">
    Ya está en «<span x-text="row.in_list"></span>»
</p>
<button type="button" x-show="row.pantry === 'out' && !row.in_list && !asking[row.id] && !added[row.id]" x-cloak
        class="btn btn-ghost btn-sm text-amber-800" @click="ask(row)">
    <x-icon name="shopping-cart" class="size-4" /> A la lista
</button>
