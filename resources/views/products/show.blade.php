@extends('layouts.app')
@section('title', $product->name)

@section('content')
<a href="{{ route('products.index') }}" class="link text-sm">← Artículos</a>
<div class="mt-2 mb-6 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-3xl font-bold text-slate-900">{{ $product->name }}</h1>
        <p class="text-sm text-slate-500">
            {{ $product->sizeLabel() }}
            @if ($product->brand) · {{ $product->brand }} @endif
            @if ($product->category) · <span class="badge badge-muted">{{ $product->category }}</span> @endif
            @if ($product->barcode) · <span class="font-mono text-xs" title="Código de barras">{{ $product->barcode }}</span> @endif
        </p>
        {{-- Despensa: lo que está en casa no se manda a la lista desde las recetas --}}
        <div x-data="pantryToggle({{ Js::from(\App\Http\Controllers\PantryController::row($product)) }})" class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
            <button type="button" class="switch" role="switch" :aria-checked="row.pantry === 'stocked'" :disabled="!!busy[row.id]"
                    aria-label="Lo tengo en casa" @click="toggle(row)"></button>
            <span class="font-medium text-slate-700" x-text="row.pantry === 'stocked' ? 'Lo tengo en casa' : (row.pantry === 'out' ? 'Se acabó' : 'No está en la despensa')"></span>
            <label x-show="row.pantry === 'stocked'" class="flex items-center gap-2 text-xs text-slate-500">
                queda
                <input type="text" class="input w-28 py-1" :value="row.qty" placeholder="suficiente" title="Cuánto queda (300 g, 1.5 kg); vacío = suficiente"
                       @change="setQty(row, $event.target.value.trim())" @keydown.enter.prevent="$event.target.blur()">
            </label>
            <span x-show="saved[row.id]" x-transition.opacity class="text-xs text-emerald-700">Guardado</span>
            <span x-show="errors[row.id]" class="text-xs text-red-600" x-text="errors[row.id]"></span>
            <a href="{{ route('pantry.index') }}" class="link text-xs">Despensa</a>
        </div>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('products.edit', $product) }}" class="btn btn-secondary">Editar</a>
        <form method="POST" action="{{ route('products.destroy', $product) }}" onsubmit="return confirm('¿Eliminar el artículo y todo su historial de precios?')">
            @csrf @method('DELETE')
            <button class="btn btn-danger">Eliminar</button>
        </form>
    </div>
</div>

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
    <div class="card">
        <div class="stat-label">Más barato ahora</div>
        @if ($cheapest)
            <div class="stat-value">{{ money($cheapest->comparePrice()) }}<span class="text-base font-normal text-slate-500">{{ $cheapest->compareLabel() }}</span></div>
            <div class="mt-1 text-sm"><span class="badge badge-cheap">★ {{ $cheapest->provider->name }}</span>
                <span class="text-slate-500">{{ money($cheapest->price) }} por {{ $cheapest->presentationLabel() }}</span></div>
        @else
            <div class="stat-value text-slate-400">—</div>
        @endif
    </div>
    <div class="card">
        <div class="stat-label">Ahorro vs. el más caro</div>
        <div class="stat-value">{{ $savings !== null && $latest->count() > 1 ? number_format($savings * 100, 1).'%' : '—' }}</div>
        <div class="mt-1 text-sm text-slate-500">comparando por unidad entre {{ $latest->count() }} proveedor(es)</div>
    </div>
    <div class="card">
        <div class="stat-label">Precios registrados</div>
        <div class="stat-value">{{ $product->prices->count() }}</div>
        <div class="mt-1 text-sm text-slate-500">
            @if ($product->prices->isNotEmpty())
                desde {{ $product->prices->last()->observed_on->translatedFormat('d M Y') }}
            @endif
        </div>
    </div>
</div>

{{-- El artículo en cada tienda: su marca y código ahí, y la página para consultar el precio de hoy --}}
<div class="card mb-6">
    <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
        <div>
            <h2 class="card-title">En cada tienda</h2>
            <p class="text-xs text-slate-500">
                Cada tienda puede vender otra marca o presentación. Pega la dirección del artículo en su página y
                «Obtener precios» trae el precio, la presentación, la marca y el código.
                Código postal: <a class="link" href="{{ route('providers.index') }}">{{ $postalCode ?: 'sin definir' }}</a>.
            </p>
        </div>
        <form method="POST" action="{{ route('products.prices.fetch', $product) }}">
            @csrf
            <button class="btn btn-primary btn-sm" @disabled($links->whereNotNull('url')->isEmpty())>Obtener precios</button>
        </form>
    </div>
    <form method="POST" action="{{ route('products.links.update', $product) }}" class="space-y-3">
        @csrf @method('PUT')
        <datalist id="store-brands">
            @foreach ($brands as $brand)
                <option value="{{ $brand }}">
            @endforeach
        </datalist>
        <div class="hidden gap-3 text-xs font-medium text-slate-500 lg:grid lg:grid-cols-[8rem_10rem_11rem_1fr]">
            <span>Tienda</span><span>Marca</span><span>Código de barras</span><span>Página del artículo</span>
        </div>
        @foreach ($providers as $provider)
            @php($link = $links->get($provider->id))
            @php($id = 'store-'.$provider->id)
            <div class="grid grid-cols-1 gap-2 border-t border-slate-100 pt-3 sm:grid-cols-2 lg:grid-cols-[8rem_10rem_11rem_1fr] lg:items-start lg:gap-3 lg:border-0 lg:pt-0">
                <div class="pt-2 text-sm font-medium text-slate-700 sm:col-span-2 lg:col-span-1">{{ $provider->name }}</div>
                <input name="stores[{{ $provider->id }}][brand]" list="store-brands" class="input" aria-label="Marca en {{ $provider->name }}"
                       value="{{ old('stores.'.$provider->id.'.brand', $link?->brand) }}" placeholder="Marca">
                <div class="flex gap-1">
                    <input id="{{ $id }}-barcode" name="stores[{{ $provider->id }}][barcode]" class="input font-mono" inputmode="numeric"
                           aria-label="Código de barras en {{ $provider->name }}" value="{{ old('stores.'.$provider->id.'.barcode', $link?->barcode) }}" placeholder="Código">
                    <x-scan-button for="{{ $id }}-barcode" tabindex="-1" />
                </div>
                <div class="min-w-0 sm:col-span-2 lg:col-span-1">
                    <div class="flex gap-2">
                        <input name="stores[{{ $provider->id }}][url]" type="url" class="input" aria-label="Página del artículo en {{ $provider->name }}"
                               value="{{ old('stores.'.$provider->id.'.url', $link?->url) }}" placeholder="https://…">
                        @if ($link?->url)
                            <a href="{{ $link->url }}" target="_blank" rel="noopener" class="btn btn-ghost shrink-0" title="Abrir la página en la tienda">↗</a>
                        @endif
                    </div>
                    @if ($link?->store_name || $link?->checked_at)
                        <p class="mt-0.5 text-xs {{ $link->error ? 'text-red-600' : 'text-slate-500' }}">
                            {{ $link->error ?: collect([$link->store_name, $link->sku ? 'código '.$link->sku : null, $link->checked_at ? 'consultado '.$link->checked_at->diffForHumans() : null])->filter()->join(' · ') }}
                        </p>
                    @endif
                </div>
            </div>
        @endforeach
        <div class="flex justify-end">
            <button class="btn btn-secondary btn-sm">Guardar</button>
        </div>
    </form>
</div>

<div class="card mb-6">
    <h2 class="card-title mb-3">Agregar precio</h2>
    @include('prices._quick', ['product' => $product, 'providers' => $providers])
    <p class="mt-2 text-xs text-slate-500">Si la presentación es distinta (ej. Costco 6 × 1 L) cambia piezas/contenido: el precio por unidad se calcula solo.</p>
</div>

<div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
    <div class="card overflow-x-auto p-0">
        <h2 class="card-title px-5 pt-5 pb-2">Último precio por proveedor</h2>
        <table class="table">
            <thead>
            <tr><th>Proveedor</th><th>Presentación</th><th class="text-right">Precio</th><th class="text-right">Por unidad</th><th class="text-right">vs. más barato</th><th>Fecha</th></tr>
            </thead>
            <tbody>
            @forelse ($latest as $i => $price)
                <tr class="{{ $i === 0 ? 'bg-emerald-50/60' : '' }}">
                    <td class="font-medium">
                        {{ $price->provider->name }}
                        @if ($i === 0)<span class="badge badge-cheap ml-1">★ Más barato</span>@endif
                    </td>
                    <td class="text-slate-600">{{ $price->presentationLabel() }}</td>
                    <td class="text-right tabular-nums">{{ money($price->price) }}</td>
                    <td class="text-right font-semibold tabular-nums">{{ money($price->comparePrice()) }}{{ $price->compareLabel() }}</td>
                    <td class="text-right tabular-nums text-xs {{ $i === 0 ? 'text-slate-400' : 'text-red-600' }}">
                        {{ $i === 0 ? '—' : pct($price->unit_price / $cheapest->unit_price - 1) }}
                    </td>
                    <td class="text-slate-500">{{ $price->observed_on->translatedFormat('d M Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-8 text-center text-slate-500">Aún no hay precios. Captura uno arriba.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2 class="card-title mb-3">Precio por unidad en el tiempo</h2>
        @if ($product->prices->count() > 1)
            <div class="h-72">
                <canvas data-chart="{{ json_encode(['type' => 'line', 'timeAxis' => true, 'format' => 'money', 'datasets' => $series]) }}"></canvas>
            </div>
        @else
            <p class="py-16 text-center text-sm text-slate-400">Se necesita más de un registro para graficar.</p>
        @endif
    </div>
</div>

<div class="card overflow-x-auto p-0">
    <h2 class="card-title px-5 pt-5 pb-2">Historial completo</h2>
    <table class="table">
        <thead>
        <tr><th>Fecha</th><th>Proveedor</th><th>Presentación</th><th class="text-right">Precio</th><th class="text-right">Por unidad</th><th>Lista</th><th></th></tr>
        </thead>
        <tbody>
        @foreach ($product->prices as $price)
            <tr>
                <td class="whitespace-nowrap text-slate-600">{{ $price->observed_on->translatedFormat('d M Y') }}</td>
                <td>{{ $price->provider->name }}</td>
                <td class="text-slate-600">{{ $price->presentationLabel() }}</td>
                <td class="text-right tabular-nums">{{ money($price->price) }}</td>
                <td class="text-right tabular-nums">{{ money($price->comparePrice()) }}{{ $price->compareLabel() }}</td>
                <td>@if ($price->shopping_list_id)<a class="link text-xs" href="{{ route('lists.show', $price->shopping_list_id) }}">Ver lista</a>@endif</td>
                <td class="text-right whitespace-nowrap">
                    <a href="{{ route('prices.edit', $price) }}" class="btn btn-ghost btn-sm">Editar</a>
                    <form method="POST" action="{{ route('prices.destroy', $price) }}" class="inline" onsubmit="return confirm('¿Eliminar este precio?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-danger btn-sm">×</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
