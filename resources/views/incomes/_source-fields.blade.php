{{--
    Campos de un ingreso recurrente. $source, $categories, $units
    Se acomoda al espacio (container query): una columna en el panel angosto, dos en la página de edición.
--}}
<div class="@container">
<div class="grid grid-cols-1 gap-3 @md:grid-cols-2">
    <div class="@md:col-span-2">
        <label class="label" for="is-name">Concepto</label>
        <input id="is-name" name="name" value="{{ old('name', $source->name) }}" class="input" maxlength="120" required placeholder="Ej. Sueldo, Vales de despensa">
    </div>
    <div>
        <x-combobox name="category" id="is-category" label="Categoría" :options="$categories" :value="old('category', $source->category)" noun="categoría nueva" placeholder="Ej. Sueldo" />
    </div>
    <div>
        <label class="label" for="is-kind">Tipo</label>
        <select id="is-kind" name="kind" class="input">
            @foreach (\App\Models\Income::KINDS as $value => $label)
                <option value="{{ $value }}" @selected(old('kind', $source->kind) === $value)>{{ $label }}{{ $value === 'in_kind' ? ' (vales, regalos…)' : '' }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <span class="label">Cada</span>
        <div class="flex gap-2">
            <input name="every" type="number" min="1" max="999" value="{{ old('every', $source->every) }}" class="input w-16 shrink-0" required aria-label="Cada cuánto">
            <select name="every_unit" class="input min-w-0" aria-label="Unidad">
                @foreach ($units as $value => [$one, $many])
                    <option value="{{ $value }}" @selected(old('every_unit', $source->every_unit) === $value)>{{ $many }}{{ $value === 'fortnight' ? ' (15 y fin de mes)' : '' }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div>
        <label class="label" for="is-next">Próxima vez</label>
        <input id="is-next" name="next_on" type="date" value="{{ old('next_on', $source->next_on?->toDateString()) }}" class="input" required>
    </div>
    <div class="@md:col-span-2">
        <label class="label" for="is-amount">Monto <span class="font-normal text-slate-400">(en especie: lo que vale)</span></label>
        <input id="is-amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount', num($source->amount)) }}" class="input" required placeholder="$">
    </div>
    <div class="@md:col-span-2">
        <label class="label" for="is-notes">Notas <span class="font-normal text-slate-400">opcional</span></label>
        <input id="is-notes" name="notes" value="{{ old('notes', $source->notes) }}" class="input" maxlength="255">
    </div>
</div>
</div>
