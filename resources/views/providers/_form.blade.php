{{--
    Campos del proveedor: nombre, notas, cadena y sucursal (Alpine providerForm,
    resources/js/provider-form.js). La sucursal sale de una lista en las cadenas que la
    publican (Chedraui, Costco) y se escribe en las demás.
--}}
@php
    $chains = collect(\App\Services\StoreBranches::CHAINS)->map(fn ($c) => [
        'name' => $c['name'],
        'list' => $c['list'],
        'hint' => $c['hint'],
        // El patrón de StoreBranches::guess() sin delimitadores, para RegExp
        'match' => substr($c['match'], 1, strrpos($c['match'], '/') - 1),
    ]);
    $idPrefix = $provider->exists ? 'provider' : 'new-provider';
@endphp
<div class="space-y-3" x-data="providerForm({{ Js::from([
        'chains' => $chains,
        'postalCode' => $postalCode,
        'branchesUrl' => route('settings.branches'),
        'providerId' => $provider->id,
        'isNew' => ! $provider->exists,
        'chain' => old('chain', $provider->chain),
        'branch' => old('branch', $provider->branch),
        'branchName' => old('branch_name', $provider->branch_name),
    ]) }})">
    <div>
        <label class="label" for="{{ $idPrefix }}-name">Nombre</label>
        <input id="{{ $idPrefix }}-name" name="name" value="{{ old('name', $provider->name) }}" class="input" placeholder="Ej. Soriana" required
               @input="guess($event.target.value)">
    </div>
    <div>
        <label class="label" for="{{ $idPrefix }}-notes">Notas</label>
        <input id="{{ $idPrefix }}-notes" name="notes" value="{{ old('notes', $provider->notes) }}" class="input" placeholder="Membresía, horario…">
    </div>
    <div>
        <label class="label" for="{{ $idPrefix }}-chain">Cadena</label>
        <select id="{{ $idPrefix }}-chain" name="chain" class="input" x-model="chain" @change="pickChain()">
            <option value="">Otra tienda (sin sucursales)</option>
            @foreach (\App\Services\StoreBranches::options() as $key => $name)
                <option value="{{ $key }}" @selected(old('chain', $provider->chain) === $key)>{{ $name }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500" x-show="info" x-text="info?.hint"></p>
    </div>

    {{-- Sucursal: de la lista de sucursales cercanas al código postal --}}
    <div x-show="chain && listable" x-cloak>
        <label class="label" for="{{ $idPrefix }}-branch-list">Sucursal</label>
        @if ($postalCode)
            <select id="{{ $idPrefix }}-branch-list" class="input" x-model="branch" @change="choose()" :disabled="loading" :name="listable ? 'branch' : null">
                <option value="">Preguntar al consultar</option>
                <option value="auto" :selected="branch === 'auto'">Automática: la más cercana que lo tenga</option>
                <template x-for="b in branches" :key="b.id">
                    <option :value="b.id" x-text="b.label" :selected="b.id === branch"></option>
                </template>
            </select>
            <p class="mt-1 text-xs text-slate-500" x-show="loading">Buscando sucursales cerca de {{ $postalCode }}…</p>
            <p class="mt-1 text-xs text-amber-700" x-show="error" x-text="error"></p>
            <p class="mt-1 text-xs text-slate-500" x-show="!loading && !error">Sucursales cerca de tu código postal {{ $postalCode }}.</p>
        @else
            <p class="text-sm text-amber-700">Guarda tu código postal (abajo, en Proveedores) para ver las sucursales.</p>
        @endif
    </div>

    {{-- Sucursal escrita a mano (Walmart, Sam's, Bodega Aurrera, Soriana) --}}
    <div x-show="chain && !listable" x-cloak class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div>
            <label class="label" for="{{ $idPrefix }}-branch">No. de tienda</label>
            <input id="{{ $idPrefix }}-branch" class="input" x-model="branch" :name="chain && !listable ? 'branch' : null" inputmode="numeric" maxlength="20" placeholder="Ej. 2344">
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="{{ $idPrefix }}-branch-name">Nombre de la sucursal</label>
            <input id="{{ $idPrefix }}-branch-name" class="input" x-model="branchName" :name="chain && !listable ? 'branch_name' : null" maxlength="150" placeholder="Ej. Walmart Universidad">
        </div>
    </div>
    <input type="hidden" name="branch_name" :value="branchName" x-bind:disabled="!(chain && listable)">
    @error('branch') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
</div>
