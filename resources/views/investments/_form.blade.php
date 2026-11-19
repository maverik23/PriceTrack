@csrf
<div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
    <div class="sm:col-span-6">
        <label class="label" for="name">Nombre</label>
        <input id="name" name="name" value="{{ old('name', $account->name) }}" class="input" maxlength="120" required autofocus placeholder="Ej. CETES directo, Afore, GBM">
    </div>
    <div class="sm:col-span-3">
        <x-combobox name="institution" label="Institución" :options="$institutions" :value="old('institution', $account->institution)" noun="institución nueva" placeholder="Ej. Cetesdirecto, GBM, Nu" />
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="type">Tipo</label>
        <select id="type" name="type" class="input">
            @foreach (\App\Models\InvestmentAccount::TYPES as $value => $label)
                <option value="{{ $value }}" @selected(old('type', $account->type) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="sm:col-span-1">
        <label class="label" for="currency">Moneda</label>
        <input id="currency" name="currency" value="{{ old('currency', $account->currency) }}" class="input uppercase" maxlength="3" required list="currencies">
        <datalist id="currencies"><option value="MXN"><option value="USD"><option value="EUR"></datalist>
    </div>
    <div class="sm:col-span-4">
        <label class="label" for="notes">Notas</label>
        <input id="notes" name="notes" value="{{ old('notes', $account->notes) }}" class="input" maxlength="255">
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="closed_on">Cerrada el <span class="font-normal text-slate-400">opcional</span></label>
        <input id="closed_on" name="closed_on" type="date" value="{{ old('closed_on', $account->closed_on?->toDateString()) }}" max="{{ today()->toDateString() }}" class="input">
    </div>
</div>
<p class="text-xs text-slate-500">Si la moneda no es MXN, cada movimiento y cada valor llevan el tipo de cambio del día y todo se reporta en pesos.</p>
