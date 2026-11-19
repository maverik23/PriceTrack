@csrf
<div class="space-y-6" x-data="{ status: {{ Js::from(old('status', $asset->status)) }}, method: {{ Js::from(old('valuation_method', $asset->valuation_method)) }} }">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
        <div class="sm:col-span-6">
            <label class="label" for="name">Nombre</label>
            <input id="name" name="name" value="{{ old('name', $asset->name) }}" class="input" maxlength="150" required autofocus placeholder="Ej. Refrigerador, Auto, Casa">
        </div>
        <div class="sm:col-span-3">
            <x-combobox name="category" label="Categoría" :options="$categories" :value="old('category', $asset->category)" noun="categoría nueva" placeholder="Ej. Electrodomésticos" />
        </div>
        <div class="sm:col-span-3">
            <x-combobox name="location" label="Ubicación" :options="$locations" :value="old('location', $asset->location)" noun="ubicación nueva" placeholder="Ej. Cocina" />
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="brand">Marca</label>
            <input id="brand" name="brand" value="{{ old('brand', $asset->brand) }}" class="input" maxlength="80">
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="model">Modelo</label>
            <input id="model" name="model" value="{{ old('model', $asset->model) }}" class="input" maxlength="80">
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="serial_number">Número de serie</label>
            <input id="serial_number" name="serial_number" value="{{ old('serial_number', $asset->serial_number) }}" class="input font-mono" maxlength="80">
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="purchased_on">Fecha de compra</label>
            <input id="purchased_on" name="purchased_on" type="date" value="{{ old('purchased_on', $asset->purchased_on?->toDateString()) }}" class="input">
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="purchase_price">Precio de compra</label>
            <input id="purchase_price" name="purchase_price" type="number" step="0.01" min="0" value="{{ old('purchase_price', num($asset->purchase_price)) }}" class="input" placeholder="$">
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="warranty_until">Garantía hasta</label>
            <input id="warranty_until" name="warranty_until" type="date" value="{{ old('warranty_until', $asset->warranty_until?->toDateString()) }}" class="input">
        </div>
    </div>

    <fieldset class="rounded-lg bg-slate-50 p-4 ring-1 ring-slate-200">
        <legend class="card-title px-1">Valor</legend>
        <p class="mb-3 text-xs text-slate-500">El valor de hoy es la última captura (o el precio de compra) ajustada con este método desde esa fecha. Las capturas se agregan en la ficha.</p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
            <div class="sm:col-span-6">
                <label class="label" for="valuation_method">Cómo cambia su valor</label>
                <select id="valuation_method" name="valuation_method" x-model="method" class="input">
                    @foreach (\App\Models\Asset::METHODS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-3" x-show="method === 'straight_line'" x-cloak>
                <label class="label" for="useful_life_years">Vida útil (años)</label>
                <input id="useful_life_years" name="useful_life_years" type="number" step="0.5" min="0.5" value="{{ old('useful_life_years', num($asset->useful_life_years)) }}" class="input" placeholder="Ej. 10">
            </div>
            <div class="sm:col-span-3" x-show="method === 'straight_line'" x-cloak>
                <label class="label" for="salvage_value">Valor de rescate <span class="font-normal text-slate-400">al final de su vida útil</span></label>
                <input id="salvage_value" name="salvage_value" type="number" step="0.01" min="0" value="{{ old('salvage_value', num($asset->salvage_value)) }}" class="input" placeholder="$0">
            </div>
            <div class="sm:col-span-3" x-show="method === 'annual_rate'" x-cloak>
                <label class="label" for="annual_rate">Porcentaje anual</label>
                <input id="annual_rate" name="annual_rate" type="number" step="0.1" min="-100" max="1000" value="{{ old('annual_rate', num($asset->annual_rate)) }}" class="input" placeholder="Ej. -15 (auto) o 6 (casa)">
            </div>
        </div>
    </fieldset>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
        <div class="sm:col-span-2">
            <label class="label" for="meter_unit">Medidor de uso <span class="font-normal text-slate-400">opcional</span></label>
            <input id="meter_unit" name="meter_unit" value="{{ old('meter_unit', $asset->meter_unit) }}" class="input" maxlength="10" placeholder="km, h" list="meter-units">
            <datalist id="meter-units"><option value="km"><option value="h"><option value="ciclos"></datalist>
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="status">Estado</label>
            <select id="status" name="status" x-model="status" class="input">
                @foreach (\App\Models\Asset::STATUSES as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-1" x-show="status !== 'active'" x-cloak>
            <label class="label" for="disposed_on">Fecha</label>
            <input id="disposed_on" name="disposed_on" type="date" value="{{ old('disposed_on', $asset->disposed_on?->toDateString()) }}" class="input">
        </div>
        <div class="sm:col-span-1" x-show="status !== 'active'" x-cloak>
            <label class="label" for="disposal_amount">Recibido</label>
            <input id="disposal_amount" name="disposal_amount" type="number" step="0.01" min="0" value="{{ old('disposal_amount', num($asset->disposal_amount)) }}" class="input" placeholder="$">
        </div>
        <div class="sm:col-span-6">
            <label class="label" for="notes">Notas</label>
            <textarea id="notes" name="notes" rows="3" class="input">{{ old('notes', $asset->notes) }}</textarea>
        </div>
    </div>
</div>

<x-attachments-field :owner="$asset" hint="Fotos, factura, póliza, garantía, manual… La primera foto es la portada." />
