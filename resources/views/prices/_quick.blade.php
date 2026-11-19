{{--
    Formulario rápido para capturar un precio de un artículo conocido.
    Variables: $product, $providers, $item (opcional, desde una lista), $redirect (opcional),
    $links (opcional: ProductLink::fetchMap(), para traer el precio de la página de la tienda)
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
    @isset($links)
        <div x-data="priceLink({{ Js::from(['links' => $links, 'product' => $product->id, 'provider' => '']) }})" x-show="link" x-cloak class="col-span-full rounded-xl bg-emerald-50 px-3 py-2.5 text-sm ring-1 ring-emerald-200">
    <div class="flex flex-wrap items-center gap-2">
        <x-icon name="link" class="size-4 text-emerald-700" />
        <span class="text-slate-700">Este artículo tiene su página en <strong x-text="link?.provider"></strong>.</span>
        <a :href="link?.url" target="_blank" rel="noopener" class="link text-xs">Abrir ↗</a>
        <button type="button" class="btn btn-secondary btn-sm ml-auto" @click="fill()" :disabled="busy">
            <x-icon name="refresh-cw" class="size-3.5" ::class="busy && 'animate-spin'" /><span x-text="busy ? 'Consultando la tienda…' : 'Traer el precio de hoy'"></span>
        </button>
    </div>
    <p x-show="status" class="mt-1 text-xs text-emerald-800" x-text="status"></p>
    <p x-show="error" class="mt-1 text-xs text-red-600" x-text="error"></p>
</div>
    @endisset
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
    <label class="col-span-full flex items-center gap-2 text-xs text-slate-600">
        <input type="checkbox" name="bulk" value="1" class="rounded border-slate-300 text-emerald-600">
        A granel (el precio es por el contenido, ej. $54 por 1 kg)
    </label>
    @if ($item)
        <label class="col-span-full flex items-center gap-2 text-xs text-slate-600">
            <input type="checkbox" name="select" value="1" checked class="rounded border-slate-300 text-emerald-600">
            Lo compré aquí (usar este precio para el total de la lista)
        </label>
    @endif
</form>
