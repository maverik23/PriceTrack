{{--
    Ventana global para elegir el código postal y la sucursal de una tienda que cambia el
    precio por sucursal (App\Services\StoreBranches). La abre pickBranch() de
    resources/js/online-prices.js cuando el servidor dice que falta; al guardar, la consulta sigue sola.
--}}
<script type="application/json" id="branch-chains">{!! json_encode(\App\Services\StoreBranches::forPage(), JSON_HEX_TAG) !!}</script>
<div x-data="branchPicker({{ Js::from(['branches' => route('settings.branches'), 'save' => route('settings.branch')]) }})"
     @pick-branch.window="show($event.detail)" @keydown.escape.window="open && close(false)"
     x-show="open" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4"
     @click.self="close(false)" role="dialog" aria-modal="true" aria-labelledby="branch-title">
    <form class="flex max-h-[90vh] w-full max-w-md flex-col rounded-t-3xl bg-surface shadow-2xl sm:rounded-2xl" @submit.prevent="save()">
        <div class="border-b border-slate-100 px-5 pt-5 pb-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="branch-title" class="font-display text-xl font-semibold text-slate-900">Tu tienda <span x-text="info?.name"></span></h2>
                    <p class="mt-1 text-sm text-slate-500"><span x-text="info?.name"></span> cambia el precio en cada sucursal. Dinos dónde compras y traemos el precio de esa tienda.</p>
                </div>
                <button type="button" class="icon-btn shrink-0" @click="close(false)" aria-label="Cancelar"><x-icon name="x" class="size-5" /></button>
            </div>
            <div class="mt-4 flex items-end gap-2">
                <div class="flex-1">
                    <label class="label" for="branch-postal">Código postal</label>
                    <input id="branch-postal" type="text" inputmode="numeric" autocomplete="postal-code" maxlength="5" class="input text-base tracking-widest"
                           x-model="postal" @input="onPostal()" placeholder="Ej. 06600">
                </div>
                <button type="button" class="btn btn-secondary" @click="search()" :disabled="loading || postal.length !== 5">
                    <span x-text="loading ? 'Buscando…' : 'Buscar'"></span>
                </button>
            </div>
        </div>
        <div class="min-h-24 overflow-y-auto px-5 py-3">
            <p x-show="loading" class="py-4 text-center text-sm text-slate-500">Buscando sucursales cercanas…</p>
            <div x-show="!loading && searched" class="space-y-1.5" role="radiogroup" aria-label="Sucursal">
                <label class="flex cursor-pointer items-start gap-3 rounded-xl px-3 py-2.5 ring-1 transition" :class="branch === 'auto' ? 'bg-emerald-50 ring-emerald-400' : 'ring-slate-200 hover:bg-slate-50'">
                    <input id="branch-auto" type="radio" name="branch" value="auto" x-model="branch" class="mt-1">
                    <span><span class="font-medium text-slate-900">Automática</span><span class="block text-xs text-slate-500">La más cercana que tenga el artículo</span></span>
                </label>
                <template x-for="b in branches" :key="b.id">
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl px-3 py-2.5 ring-1 transition" :class="branch === b.id ? 'bg-emerald-50 ring-emerald-400' : 'ring-slate-200 hover:bg-slate-50'">
                        <input type="radio" name="branch" :value="b.id" x-model="branch" class="mt-1">
                        <span class="text-sm text-slate-800" x-text="b.label"></span>
                    </label>
                </template>
            </div>
            <p x-show="!loading && !searched" class="py-4 text-center text-sm text-slate-500">Escribe tu código postal para ver las sucursales.</p>
            <p x-show="error" class="mt-2 text-sm text-red-600" x-text="error" role="alert"></p>
        </div>
        <div class="flex gap-2 border-t border-slate-100 px-5 py-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
            <button type="button" class="btn btn-ghost flex-1" @click="close(false)">Ahora no</button>
            <button class="btn btn-primary flex-[2]" :disabled="saving || postal.length !== 5 || loading">
                <span x-text="saving ? 'Guardando…' : 'Guardar y seguir'"></span>
            </button>
        </div>
        <p class="px-5 pb-4 text-center text-xs text-slate-400">Se puede cambiar después en <a class="link" href="{{ route('providers.index') }}">Proveedores</a>.</p>
    </form>
</div>
