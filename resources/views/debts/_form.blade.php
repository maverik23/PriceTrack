@csrf
<div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
    <div class="sm:col-span-4">
        <label class="label" for="name">Nombre</label>
        <input id="name" name="name" value="{{ old('name', $debt->name) }}" class="input" maxlength="120" required autofocus placeholder="Ej. Hipoteca, Crédito del auto">
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="type">Tipo</label>
        <select id="type" name="type" class="input">
            @foreach (\App\Models\Debt::TYPES as $value => $label)
                <option value="{{ $value }}" @selected(old('type', $debt->type) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="lender">Acreedor</label>
        <input id="lender" name="lender" value="{{ old('lender', $debt->lender) }}" class="input" maxlength="80" placeholder="Ej. Infonavit, BBVA">
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="original_amount">Monto original</label>
        <input id="original_amount" name="original_amount" type="number" step="0.01" min="0.01" value="{{ old('original_amount', num($debt->original_amount)) }}" class="input" required placeholder="$">
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="annual_rate">Tasa anual (%) <span class="font-normal text-slate-400">opcional</span></label>
        <input id="annual_rate" name="annual_rate" type="number" step="0.001" min="0" max="500" value="{{ old('annual_rate', num($debt->annual_rate)) }}" class="input" placeholder="Ej. 10.5">
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="started_on">Empezó el</label>
        <input id="started_on" name="started_on" type="date" value="{{ old('started_on', $debt->started_on?->toDateString()) }}" max="{{ today()->toDateString() }}" class="input">
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="paid_off_on">Liquidada el <span class="font-normal text-slate-400">opcional</span></label>
        <input id="paid_off_on" name="paid_off_on" type="date" value="{{ old('paid_off_on', $debt->paid_off_on?->toDateString()) }}" max="{{ today()->toDateString() }}" class="input">
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="notes">Notas</label>
        <input id="notes" name="notes" value="{{ old('notes', $debt->notes) }}" class="input" maxlength="255">
    </div>
</div>
<p class="text-xs text-slate-500">El saldo se calcula con el monto original (o la última captura del estado de cuenta), el interés de la tasa y los pagos. Captura el saldo de vez en cuando para que cuadre.</p>
