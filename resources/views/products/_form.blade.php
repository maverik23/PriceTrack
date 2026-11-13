@csrf
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2"
     x-data="productForm({{ Js::from(['lookupUrl' => route('products.lookup'), 'barcode' => (string) old('barcode', $product->barcode), 'productId' => $product->id, 'autoLookup' => $autoLookup ?? false]) }})">
    <div class="sm:col-span-2">
        <label class="label" for="barcode">Código de barras <span class="font-normal text-slate-400">(opcional)</span></label>
        <div class="flex gap-2">
            <input id="barcode" name="barcode" x-model="barcode" class="input font-mono" inputmode="numeric" autocomplete="off"
                   placeholder="Escanéalo o escríbelo, ej. 7501055900084"
                   @keydown.enter.prevent="lookup()" @change="lookup()" @barcode-scanned="lookup()">
            <x-scan-button for="barcode" tabindex="-1" />
            <button type="button" tabindex="-1" class="btn btn-secondary" :disabled="loading" @click="lookup(true)">Buscar datos</button>
        </div>
        <p class="mt-1 text-xs text-slate-500" x-show="loading" x-cloak>Buscando en internet…</p>
        <p class="mt-1 text-xs text-slate-500" x-show="!loading && !message && !found && !existing">
            Con el código se completan nombre, marca, categoría y presentación desde internet (Open Food Facts).
        </p>
        <p class="mt-1 text-xs text-amber-700" x-show="message" x-text="message" x-cloak></p>
        <template x-if="existing">
            <p class="mt-1 text-xs text-amber-700">Este código ya es de <a :href="existing.url" class="link" x-text="existing.name"></a>.</p>
        </template>
        <template x-if="found">
            <div class="mt-2 flex items-start gap-3 rounded-lg bg-emerald-50/60 p-3 ring-1 ring-emerald-200">
                <img x-show="found.image" :src="found.image" alt="" class="size-14 shrink-0 rounded bg-white object-contain ring-1 ring-slate-200">
                <div class="min-w-0 flex-1 text-sm">
                    <div class="font-medium text-slate-900" x-text="found.name"></div>
                    <div class="text-xs text-slate-600" x-text="[found.brand, found.presentation, found.category].filter(Boolean).join(' · ')"></div>
                    <div class="mt-1 text-xs text-slate-500">
                        Se llenaron los campos vacíos con datos de <a :href="found.source_url" target="_blank" rel="noopener" class="link" x-text="found.source"></a>. Revísalos antes de guardar.
                    </div>
                </div>
                <button type="button" tabindex="-1" class="btn btn-secondary btn-sm" @click="apply(true)">Usar estos datos</button>
            </div>
        </template>
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="name">Nombre del artículo</label>
        <input id="name" name="name" value="{{ old('name', $product->name) }}" class="input" placeholder="Ej. Salsa de tomate 345 ml" required autofocus>
    </div>
    <x-combobox name="brand" label="Marca" :options="$brands" :value="old('brand', $product->brand)"
                noun="marca nueva" placeholder="Ej. Bimbo" />
    <x-combobox name="category" label="Categoría" :options="$categories" :value="old('category', $product->category)"
                noun="categoría nueva" placeholder="Ej. Lácteos" />
    <div>
        <label class="label" for="size">Presentación habitual (contenido)</label>
        <input id="size" name="size" type="number" step="0.001" min="0.001" value="{{ old('size', num($product->size)) }}" class="input" required>
    </div>
    <div>
        <label class="label" for="unit">Unidad</label>
        <select id="unit" name="unit" class="input">
            @foreach (\App\Enums\Unit::options() as $value => $label)
                <option value="{{ $value }}" @selected(old('unit', $product->unit?->value) === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">Se compara en $/L (ml, L), $/kg (g, kg) o $/pza (item).</p>
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="notes">Notas</label>
        <input id="notes" name="notes" value="{{ old('notes', $product->notes) }}" class="input">
    </div>
</div>
