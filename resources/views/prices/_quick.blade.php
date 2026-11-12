{{--
    Formulario rápido para capturar un precio de un artículo conocido.
    Variables: $product, $providers, $item (opcional, desde una lista), $redirect (opcional)
--}}
@php
    $item = $item ?? null;
    $uid = 'pf'.($item?->id ?? 'p'.$product->id);
@endphp
<form method="POST" action="{{ route('prices.store') }}" class="grid grid-cols-2 items-end gap-2 sm:grid-cols-4">
    @csrf
    @if ($item)
        <input type="hidden" name="item_id" value="{{ $item->id }}">
    @else
        <input type="hidden" name="product_id" value="{{ $product->id }}">
    @endif
    @isset($redirect)
        <input type="hidden" name="redirect" value="{{ $redirect }}">
    @endisset

    <div class="col-span-2">
        <label class="label" for="{{ $uid }}-provider">Proveedor</label>
        <select id="{{ $uid }}-provider" name="provider_id" class="input" required>
            <option value="">Elegir…</option>
            @foreach ($providers as $provider)
                <option value="{{ $provider->id }}">{{ $provider->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="label" for="{{ $uid }}-price">Precio $</label>
        <input id="{{ $uid }}-price" name="price" type="number" step="0.01" min="0.01" class="input" placeholder="0.00" required>
    </div>
    <div>
        <label class="label" for="{{ $uid }}-pack" title="Piezas en el paquete, ej. 6 en un six-pack">Piezas/paq.</label>
        <input id="{{ $uid }}-pack" name="pack_qty" type="number" step="1" min="1" value="1" class="input">
    </div>
    <div>
        <label class="label" for="{{ $uid }}-size">Contenido</label>
        <input id="{{ $uid }}-size" name="size" type="number" step="0.001" min="0.001" value="{{ num($product->size) }}" class="input" required>
    </div>
    <div>
        <label class="label" for="{{ $uid }}-unit">Unidad</label>
        <select id="{{ $uid }}-unit" name="unit" class="input">
            @foreach ($product->unit->compatibleUnits() as $unit)
                <option value="{{ $unit->value }}" @selected($unit === $product->unit)>{{ $unit->label() }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="label" for="{{ $uid }}-date">Fecha</label>
        <input id="{{ $uid }}-date" name="observed_on" type="date" value="{{ $item?->shoppingList?->shopped_on?->isFuture() === false ? $item->shoppingList->shopped_on->format('Y-m-d') : today()->format('Y-m-d') }}" max="{{ today()->format('Y-m-d') }}" class="input" required>
    </div>
    <div class="flex flex-col gap-1">
        <span data-unit-preview class="h-4 text-xs font-medium text-emerald-700 tabular-nums"></span>
        <button class="btn btn-primary">Guardar</button>
    </div>
    @if ($item)
        <label class="col-span-full flex items-center gap-2 text-xs text-slate-600">
            <input type="checkbox" name="select" value="1" checked class="rounded border-slate-300 text-emerald-600">
            Lo compré aquí (usar este precio para el total de la lista)
        </label>
    @endif
</form>
