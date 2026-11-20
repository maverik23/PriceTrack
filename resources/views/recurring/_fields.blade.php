{{--
    Campos de un pago recurrente. $payment, $categories, $debts, $units
    Se acomoda al espacio (container query): angosto en el panel de Ingresos y pagos, tres por renglón en la página de edición.
--}}
<div class="@container">
<div class="grid grid-cols-2 gap-3 @2xl:grid-cols-6">
    <div class="col-span-2">
        <label class="label" for="rp-name">Qué se paga</label>
        <input id="rp-name" name="name" value="{{ old('name', $payment->name) }}" class="input" maxlength="120" required placeholder="Ej. Luz, Internet, Netflix">
    </div>
    <div class="col-span-2">
        <x-combobox name="category" id="rp-category" label="Categoría" :options="$categories" :value="old('category', $payment->category)" noun="categoría nueva" placeholder="Ej. Servicios" />
    </div>
    <div class="@2xl:col-span-2">
        <label class="label" for="rp-amount">Monto</label>
        <input id="rp-amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount', num($payment->amount)) }}" class="input" required placeholder="$">
    </div>
    <div class="@2xl:col-span-2">
        <label class="label" for="rp-meter">Lectura en <span class="font-normal text-slate-400">opcional</span></label>
        <input id="rp-meter" name="meter_unit" value="{{ old('meter_unit', $payment->meter_unit) }}" class="input" maxlength="12" list="rp-meter-units" placeholder="m³, kWh…"
               title="Si el recibo trae lectura de medidor (agua, luz, gas), su unidad: con ella se calcula el consumo y el precio por unidad">
        <datalist id="rp-meter-units"><option value="m³"><option value="kWh"><option value="L"><option value="GB"></datalist>
    </div>
    <div class="col-span-2">
        <span class="label">Cada</span>
        <div class="flex gap-2">
            <input name="every" type="number" min="1" max="999" value="{{ old('every', $payment->every) }}" class="input w-20" required aria-label="Cada cuánto">
            <select name="every_unit" class="input" aria-label="Unidad">
                @foreach ($units as $value => [$one, $many])
                    <option value="{{ $value }}" @selected(old('every_unit', $payment->every_unit) === $value)>{{ $many }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="col-span-2">
        <label class="label" for="rp-due">Próximo pago</label>
        <input id="rp-due" name="next_due_on" type="date" value="{{ old('next_due_on', $payment->next_due_on?->toDateString()) }}" class="input" required>
    </div>
    <div class="col-span-2">
        <label class="label" for="rp-debt">Es la mensualidad de <span class="font-normal text-slate-400">opcional</span></label>
        <select id="rp-debt" name="debt_id" class="input">
            <option value="">Ninguna deuda</option>
            @foreach ($debts as $debt)
                <option value="{{ $debt->id }}" @selected((string) old('debt_id', $payment->debt_id) === (string) $debt->id)>{{ $debt->name }}</option>
            @endforeach
        </select>
    </div>
</div>
</div>
