@csrf
<div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
    <div class="sm:col-span-4">
        <label class="label" for="name">Nombre de la receta</label>
        <input id="name" name="name" value="{{ old('name', $recipe->name) }}" class="input" placeholder="Ej. Arroz rojo" required autofocus>
    </div>
    <div class="sm:col-span-2">
        <x-combobox name="category" label="Categoría" :options="$categories" :value="old('category', $recipe->category)"
                    noun="categoría nueva" placeholder="Ej. Guisados" />
    </div>
    <div>
        <label class="label" for="servings">Porciones que rinde</label>
        <input id="servings" name="servings" type="number" min="1" max="999" value="{{ old('servings', $recipe->servings) }}" class="input" required>
    </div>
    <div>
        <label class="label" for="minutes">Tiempo (min) <span class="font-normal text-slate-400">opcional</span></label>
        <input id="minutes" name="minutes" type="number" min="1" max="9999" value="{{ old('minutes', $recipe->minutes) }}" class="input">
    </div>
</div>

<div x-data="recipeForm({{ Js::from($form) }})">
    <div class="mb-1 flex items-end justify-between gap-2">
        <h2 class="card-title">Ingredientes</h2>
        <p class="text-xs text-slate-500">Liga cada ingrediente a un artículo para saber cuánto cuesta y mandarlo a tu lista.</p>
    </div>
    <div class="space-y-2">
        <template x-for="(row, i) in rows" :key="row.key">
            <div class="grid grid-cols-1 gap-2 rounded-lg bg-slate-50 p-2 ring-1 ring-slate-200 sm:grid-cols-12">
                <div class="sm:col-span-4">
                    <select class="input" :name="`ingredients[${i}][product_id]`" x-model="row.product_id" @change="onProduct(row)" aria-label="Artículo">
                        <option value="">Texto libre (sin artículo)</option>
                        <template x-for="p in products" :key="p.id">
                            <option :value="p.id" :selected="String(p.id) === String(row.product_id)" x-text="p.name"></option>
                        </template>
                    </select>
                    <input class="input mt-2" :name="`ingredients[${i}][name]`" x-model="row.name" x-show="!row.product_id"
                           placeholder="Ingrediente, ej. Sal" maxlength="150" aria-label="Ingrediente">
                </div>
                <div class="sm:col-span-2">
                    <input class="input" type="number" step="0.001" min="0.001" :name="`ingredients[${i}][quantity]`" x-model="row.quantity"
                           placeholder="Cantidad" :required="!!row.product_id" aria-label="Cantidad">
                </div>
                <div class="sm:col-span-2">
                    <select class="input" :name="`ingredients[${i}][unit]`" x-model="row.unit" :required="!!row.product_id" aria-label="Unidad">
                        <option value="">Unidad</option>
                        <template x-for="u in unitsFor(row)" :key="u">
                            <option :value="u" :selected="u === row.unit" x-text="units[u].label"></option>
                        </template>
                    </select>
                </div>
                <div class="sm:col-span-3">
                    <input class="input" :name="`ingredients[${i}][note]`" x-model="row.note" placeholder="Nota: picado, al gusto…" maxlength="255" aria-label="Nota">
                </div>
                <div class="flex items-start justify-end sm:col-span-1">
                    <button type="button" tabindex="-1" class="btn btn-danger btn-sm" @click="remove(i)" title="Quitar ingrediente">✕</button>
                </div>
            </div>
        </template>
    </div>
    <button type="button" class="btn btn-secondary btn-sm mt-2" @click="add()">+ Ingrediente</button>
</div>

<div>
    <label class="label" for="instructions">Preparación <span class="font-normal text-slate-400">(un paso por renglón)</span></label>
    <textarea id="instructions" name="instructions" rows="8" class="input">{{ old('instructions', $recipe->instructions) }}</textarea>
</div>
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label class="label" for="source_url">De dónde salió <span class="font-normal text-slate-400">(dirección, opcional)</span></label>
        <input id="source_url" name="source_url" type="url" value="{{ old('source_url', $recipe->source_url) }}" class="input" placeholder="https://…">
    </div>
    <div>
        <label class="label" for="notes">Notas</label>
        <input id="notes" name="notes" value="{{ old('notes', $recipe->notes) }}" class="input">
    </div>
</div>
