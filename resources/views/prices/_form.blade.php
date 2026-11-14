@csrf
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="label" for="product_id">Artículo</label>
        <select id="product_id" name="product_id" class="input" required data-prefill-product>
            <option value="">Elegir artículo…</option>
            @foreach ($products as $product)
                <option value="{{ $product->id }}" data-size="{{ num($product->size) }}" data-unit="{{ $product->unit->value }}"
                    @selected((int) old('product_id', $price->product_id) === $product->id)>
                    {{ $product->name }} — {{ $product->sizeLabel() }}{{ $product->brand ? ' · '.$product->brand : '' }}
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">¿No está? <a class="link" href="{{ route('products.create') }}">Crear artículo</a></p>
    </div>
    <div>
        <label class="label" for="provider_id">Proveedor</label>
        <select id="provider_id" name="provider_id" class="input" required>
            <option value="">Elegir proveedor…</option>
            @foreach ($providers as $provider)
                <option value="{{ $provider->id }}" @selected((int) old('provider_id', $price->provider_id) === $provider->id)>{{ $provider->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="label" for="observed_on">Fecha</label>
        <input id="observed_on" name="observed_on" type="date" max="{{ today()->format('Y-m-d') }}"
               value="{{ old('observed_on', $price->observed_on?->format('Y-m-d')) }}" class="input" required>
    </div>
    <div>
        <label class="label" for="price">Precio del paquete ($)</label>
        <input id="price" name="price" type="number" step="0.01" min="0.01" value="{{ old('price', $price->price) }}" class="input" required>
    </div>
    <div>
        <label class="label" for="pack_qty">Piezas en el paquete</label>
        <input id="pack_qty" name="pack_qty" type="number" step="1" min="1" value="{{ old('pack_qty', $price->pack_qty ?? 1) }}" class="input">
    </div>
    <div>
        <label class="label" for="size">Contenido por pieza</label>
        <input id="size" name="size" type="number" step="0.001" min="0.001" value="{{ old('size', num($price->size)) }}" class="input" required>
    </div>
    <div>
        <label class="label" for="unit">Unidad</label>
        <select id="unit" name="unit" class="input">
            @foreach (\App\Enums\Unit::options() as $value => $label)
                <option value="{{ $value }}" @selected(old('unit', $price->unit?->value) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <label class="flex items-start gap-2 text-sm text-slate-600 sm:col-span-2">
        <input type="hidden" name="bulk" value="0">
        <input type="checkbox" name="bulk" value="1" @checked(old('bulk', $price->bulk)) class="mt-0.5 rounded border-slate-300 text-emerald-600">
        <span>A granel: el precio es por el contenido (ej. $54 por 1 kg) y compras la cantidad que quieras.</span>
    </label>
    <div class="sm:col-span-2">
        <label class="label" for="notes">Notas</label>
        <input id="notes" name="notes" value="{{ old('notes', $price->notes) }}" class="input" placeholder="Oferta, 2×1, sucursal…">
    </div>
</div>
<p class="mt-3 text-sm font-semibold text-emerald-700 tabular-nums" data-unit-preview></p>
