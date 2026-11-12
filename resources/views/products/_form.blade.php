@csrf
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
