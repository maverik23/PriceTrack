@csrf
<div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
    <div class="sm:col-span-4">
        <label class="label" for="name">Nombre de la receta</label>
        <input id="name" name="name" value="{{ old('name', $recipe->name) }}" class="input" placeholder="Ej. Arroz rojo" required @if (! $recipe->exists) autofocus @endif>
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
    <div class="sm:col-span-2">
        <span class="label">Etiquetas <span class="font-normal text-slate-400">opcional</span></span>
        <div class="relative" x-data="tagInput({{ Js::from($tagOptions) }}, {{ Js::from(array_values($tags)) }})">
            <div class="input flex min-h-10 flex-wrap items-center gap-1.5 py-1.5" @click="$event.currentTarget.querySelector('input[type=text]').focus()">
                <template x-for="(tag, i) in tags" :key="tag">
                    <span class="badge badge-muted inline-flex items-center gap-1 py-0.5">
                        <span x-text="tag"></span>
                        <input type="hidden" name="tags[]" :value="tag">
                        <button type="button" tabindex="-1" class="text-slate-400 hover:text-red-600" :aria-label="'Quitar ' + tag" @click.stop="remove(i)">✕</button>
                    </span>
                </template>
                <input type="text" class="min-w-24 flex-1 border-0 bg-transparent p-0 text-sm outline-none placeholder:text-slate-400" x-model="text"
                       :placeholder="tags.length ? '' : 'Mealprep, Rápidas…'" aria-label="Agregar etiqueta" autocomplete="off"
                       @focus="open = true" @input="open = true; hi = 0" @keydown="onKey($event)" @blur="settle()">
            </div>
            <div x-show="open && suggestions.length" x-cloak @mousedown.prevent
                 class="absolute z-30 mt-1 max-h-56 w-full overflow-auto rounded-lg bg-surface py-1 shadow-lg ring-1 ring-slate-200">
                <template x-for="(opt, i) in suggestions" :key="opt.label">
                    <button type="button" tabindex="-1" class="block w-full px-3 py-2 text-left text-sm" :class="i === hi ? 'bg-emerald-50' : 'hover:bg-slate-50'"
                            @mouseenter="hi = i" @click="add(opt.label)">
                        <span :class="opt.isNew ? 'font-medium text-emerald-700' : 'text-slate-900'" x-text="opt.isNew ? '+ Nueva etiqueta «' + opt.label + '»' : opt.label"></span>
                    </button>
                </template>
            </div>
            <p class="mt-1 text-xs text-slate-500">Enter o coma para agregar. Sirven para filtrar el listado.</p>
        </div>
    </div>
    <div>
        <span class="label">Calificación</span>
        <div x-data="{ rating: {{ (int) old('rating', $recipe->rating) }} }" class="flex items-center gap-0.5">
            <input type="hidden" name="rating" :value="rating || ''">
            @for ($i = 1; $i <= 5; $i++)
                <button type="button" class="grid size-9 place-items-center rounded-full transition hover:bg-slate-100" @click="rating = rating === {{ $i }} ? 0 : {{ $i }}"
                        :class="rating >= {{ $i }} ? 'text-amber-500' : 'text-slate-300'" aria-label="{{ $i }} de 5" :aria-pressed="rating === {{ $i }}">
                    <x-icon name="star" class="size-6" ::fill="rating >= {{ $i }} ? 'currentColor' : 'none'" />
                </button>
            @endfor
        </div>
    </div>
    <div>
        <label class="label" for="yield_qty">Rinde en total <span class="font-normal text-slate-400">opcional</span></label>
        <div class="flex gap-2">
            <input id="yield_qty" name="yield_qty" type="number" step="0.001" min="0.001" value="{{ old('yield_qty', num($recipe->yield_qty)) }}" class="input" placeholder="1200">
            <select name="yield_unit" class="input w-20 shrink-0" aria-label="Unidad del rendimiento">
                @foreach (\App\Support\RecipeData::YIELD_UNITS as $unit)
                    <option value="{{ $unit }}" @selected(old('yield_unit', $recipe->yield_unit ?? 'g') === $unit)>{{ \App\Enums\Unit::from($unit)->label() }}</option>
                @endforeach
            </select>
        </div>
        <p class="mt-1 text-xs text-slate-500">Para usarla en otras recetas por peso o volumen.</p>
    </div>
</div>

<div x-data="recipeForm({{ Js::from($form) }})" class="space-y-8">
    {{-- Ingredientes, por sección --}}
    <section>
        <div class="mb-2 flex flex-wrap items-end justify-between gap-2">
            <h2 class="card-title">Ingredientes</h2>
            <p class="text-xs text-slate-500">Liga cada ingrediente a un artículo para saber cuánto cuesta y mandarlo a tu lista, o a otra receta (sub-receta).</p>
        </div>
        <div class="space-y-4">
            <template x-for="(section, si) in sections" :key="section.key">
                <div class="space-y-2">
                    <div x-show="sections.length > 1 || section.name" class="flex items-center gap-2">
                        <input :id="'section-' + section.key" type="text" class="input max-w-xs py-1.5 font-semibold" x-model="section.name"
                               placeholder="Nombre de la sección (ej. Salsa)" maxlength="80" aria-label="Sección">
                        <span class="text-xs text-slate-400" x-show="!section.name">sin nombre</span>
                    </div>
                    <template x-for="(row, ri) in section.items" :key="row.key">
                        <div class="grid grid-cols-1 gap-2 rounded-xl bg-slate-50 p-2 ring-1 ring-slate-200 sm:grid-cols-12">
                            <input type="hidden" :name="`ingredients[${index(si, ri)}][section]`" :value="section.name">
                            <input type="hidden" :name="`ingredients[${index(si, ri)}][product_id]`" :value="row.product_id">
                            <input type="hidden" :name="`ingredients[${index(si, ri)}][subrecipe_id]`" :value="row.subrecipe_id">
                            <div class="sm:col-span-4">
                                <select class="input" x-model="row.ref" @change="onRef(row)" aria-label="Artículo o receta">
                                    <option value="">Texto libre (sin artículo)</option>
                                    <optgroup label="Artículos del súper">
                                        <template x-for="p in products" :key="'p' + p.id">
                                            <option :value="'p' + p.id" :selected="'p' + p.id === row.ref" x-text="p.name"></option>
                                        </template>
                                    </optgroup>
                                    <optgroup label="Recetas (sub-receta)" x-show="recipes.length">
                                        <template x-for="r in recipes" :key="'r' + r.id">
                                            <option :value="'r' + r.id" :selected="'r' + r.id === row.ref" x-text="'↳ ' + r.name"></option>
                                        </template>
                                    </optgroup>
                                </select>
                                <input class="input mt-2" :name="`ingredients[${index(si, ri)}][name]`" x-model="row.name" x-show="!row.ref"
                                       placeholder="Ingrediente, ej. Sal" maxlength="150" aria-label="Ingrediente">
                                <p class="mt-1 text-xs text-slate-500" x-show="hint(row)" x-text="hint(row)"></p>
                            </div>
                            <div class="grid grid-cols-2 gap-2 sm:col-span-4">
                                <input class="input" type="number" step="0.001" min="0.001" :name="`ingredients[${index(si, ri)}][quantity]`" x-model="row.quantity"
                                       placeholder="Cantidad" :required="!!row.ref" aria-label="Cantidad">
                                <select class="input" :name="`ingredients[${index(si, ri)}][unit]`" x-model="row.unit" :required="!!row.ref" aria-label="Unidad">
                                    <option value="">Unidad</option>
                                    <template x-for="u in unitsFor(row)" :key="u">
                                        <option :value="u" :selected="u === row.unit" x-text="units[u].label"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="sm:col-span-3">
                                <input class="input" :name="`ingredients[${index(si, ri)}][note]`" x-model="row.note" placeholder="Nota: picado, al gusto…" maxlength="255" aria-label="Nota">
                            </div>
                            <div class="flex items-start justify-end gap-0.5 sm:col-span-1">
                                <button type="button" tabindex="-1" class="icon-btn size-8" @click="moveRow(section, ri, -1)" :disabled="ri === 0" title="Subir" aria-label="Subir ingrediente">↑</button>
                                <button type="button" tabindex="-1" class="icon-btn size-8 text-red-600" @click="removeRow(section, ri)" title="Quitar ingrediente" aria-label="Quitar ingrediente">✕</button>
                            </div>
                        </div>
                    </template>
                    <button type="button" class="btn btn-secondary btn-sm" @click="addRow(section)" x-text="section.name ? '+ Ingrediente en ' + section.name : '+ Ingrediente'">+ Ingrediente</button>
                </div>
            </template>
        </div>
        <button type="button" class="btn btn-ghost btn-sm mt-3" @click="addSection()">+ Sección (ej. Salsa, Para servir)</button>
    </section>

    {{-- Preparación, por etapa --}}
    <section>
        <div class="mb-2 flex flex-wrap items-end justify-between gap-2">
            <h2 class="card-title">Preparación</h2>
            <p class="text-xs text-slate-500">Un paso por cuadro; pega varios renglones en un paso vacío y se reparten. Los minutos dan el temporizador del modo cocinar.</p>
        </div>
        <div class="space-y-4">
            <template x-for="(stage, gi) in stages" :key="stage.key">
                <div class="space-y-2">
                    <div x-show="stages.length > 1 || stage.name" class="flex items-center gap-2">
                        <input :id="'stage-' + stage.key" type="text" class="input max-w-xs py-1.5 font-semibold" x-model="stage.name"
                               placeholder="Etapa (ej. Salsa, Montaje)" maxlength="80" aria-label="Etapa">
                    </div>
                    <template x-for="(step, si) in stage.items" :key="step.key">
                        <div class="flex gap-2 rounded-xl bg-slate-50 p-2 ring-1 ring-slate-200">
                            <span class="mt-2 grid size-6 shrink-0 place-items-center rounded-full bg-surface text-xs font-semibold text-slate-600 ring-1 ring-slate-300" x-text="stepNumber(gi, si)"></span>
                            <input type="hidden" :name="`steps[${stepIndex(gi, si)}][stage]`" :value="stage.name">
                            <div class="grid min-w-0 flex-1 grid-cols-1 gap-2 sm:grid-cols-12">
                                <textarea :id="'step-' + step.key" class="input sm:col-span-10" rows="2" :name="`steps[${stepIndex(gi, si)}][body]`" x-model="step.body"
                                          placeholder="Describe el paso…" maxlength="5000" aria-label="Paso" @paste="pasteSteps($event, stage, si)"></textarea>
                                <label class="flex items-center gap-1.5 text-xs text-slate-500 sm:col-span-2" title="Minutos para el temporizador (opcional)">
                                    <x-icon name="timer" class="size-4 shrink-0" />
                                    <input class="input px-2" type="number" min="1" max="1440" :name="`steps[${stepIndex(gi, si)}][minutes]`" x-model="step.minutes" placeholder="—" aria-label="Minutos del paso">
                                    <span>min</span>
                                </label>
                            </div>
                            <div class="flex shrink-0 flex-col gap-0.5">
                                <button type="button" tabindex="-1" class="icon-btn size-8" @click="moveStep(stage, si, -1)" :disabled="si === 0" title="Subir" aria-label="Subir paso">↑</button>
                                <button type="button" tabindex="-1" class="icon-btn size-8 text-red-600" @click="removeStep(stage, si)" title="Quitar paso" aria-label="Quitar paso">✕</button>
                            </div>
                        </div>
                    </template>
                    <button type="button" class="btn btn-secondary btn-sm" @click="addStep(stage)" x-text="stage.name ? '+ Paso en ' + stage.name : '+ Paso'">+ Paso</button>
                </div>
            </template>
        </div>
        <button type="button" class="btn btn-ghost btn-sm mt-3" @click="addStage()">+ Etapa (ej. Salsa, Albóndigas, Montaje)</button>
    </section>
</div>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="label" for="notes">Notas</label>
        <textarea id="notes" name="notes" rows="4" class="input" placeholder="Variaciones, cómo se guarda, de dónde salió…">{{ old('notes', $recipe->notes) }}</textarea>
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="source_url">De dónde salió <span class="font-normal text-slate-400">(dirección, opcional)</span></label>
        <input id="source_url" name="source_url" type="url" value="{{ old('source_url', $recipe->source_url) }}" class="input" placeholder="https://…">
    </div>
</div>
