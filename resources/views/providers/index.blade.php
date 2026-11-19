@extends('layouts.app')
@section('title', 'Proveedores')

@section('content')
<div class="mb-6 flex items-center justify-between">
    <div>
        <h1 class="text-3xl font-bold text-slate-900">Proveedores</h1>
        <p class="text-sm text-slate-500">Tiendas donde comparas precios: Sam's, Costco, Walmart, Amazon…</p>
    </div>
</div>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="card lg:col-span-2 overflow-x-auto p-0">
        <table class="table">
            <thead>
            <tr>
                <th>Nombre</th>
                <th>Notas</th>
                <th class="text-right">Precios</th>
                <th>Último registro</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($providers as $provider)
                <tr>
                    <td class="font-medium text-slate-900">{{ $provider->name }}</td>
                    <td class="text-slate-500">{{ $provider->notes }}</td>
                    <td class="text-right tabular-nums">
                        <a class="link" href="{{ route('prices.index', ['provider_id' => $provider->id]) }}">{{ $provider->prices_count }}</a>
                    </td>
                    <td class="text-slate-500">{{ $provider->prices_max_observed_on ? \Illuminate\Support\Carbon::parse($provider->prices_max_observed_on)->translatedFormat('d M Y') : '—' }}</td>
                    <td class="text-right whitespace-nowrap">
                        <a href="{{ route('providers.edit', $provider) }}" class="btn btn-ghost btn-sm">Editar</a>
                        <form method="POST" action="{{ route('providers.destroy', $provider) }}" class="inline"
                              onsubmit="return confirm('¿Eliminar {{ addslashes($provider->name) }} y sus {{ $provider->prices_count }} precios?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Eliminar</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-8 text-center text-slate-500">Aún no hay proveedores. Agrega el primero →</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="space-y-6">
    <form method="POST" action="{{ route('providers.store') }}" class="card space-y-3">
        @csrf
        <h2 class="card-title">Nuevo proveedor</h2>
        <div>
            <label class="label" for="name">Nombre</label>
            <input id="name" name="name" value="{{ old('name') }}" class="input" placeholder="Ej. Soriana" required>
        </div>
        <div>
            <label class="label" for="notes">Notas</label>
            <input id="notes" name="notes" value="{{ old('notes') }}" class="input" placeholder="Sucursal, membresía…">
        </div>
        <button class="btn btn-primary w-full">Agregar</button>
    </form>

    {{-- Para pedir a las tiendas en línea el precio de tu zona --}}
    <form method="POST" action="{{ route('settings.update') }}" class="card space-y-3">
        @csrf @method('PUT')
        <h2 class="card-title">Código postal</h2>
        <div>
            <label class="label" for="postal_code">Para consultar precios en línea</label>
            <input id="postal_code" name="postal_code" value="{{ old('postal_code', $postalCode) }}" class="input" inputmode="numeric" maxlength="5" placeholder="Ej. 06600">
            <p class="mt-1 text-xs text-slate-500">Chedraui cambia el precio por tienda; Costco y Home Depot dan su precio en línea.</p>
        </div>
        @if ($postalCode)
            <div>
                <label class="label" for="chedraui_store">Tu tienda Chedraui</label>
                <select id="chedraui_store" name="chedraui_store" class="input">
                    <option value="">Automática: la más cercana que lo tenga</option>
                    @foreach ($chedrauiStores as $seller => $name)
                        <option value="{{ $seller }}" @selected($chedrauiStore === $seller)>{{ $name }}</option>
                    @endforeach
                    @if ($chedrauiStore && ! isset($chedrauiStores[$chedrauiStore]))
                        <option value="{{ $chedrauiStore }}" selected>{{ $chedrauiStore }}</option>
                    @endif
                </select>
                @if (! $chedrauiStores)
                    <p class="mt-1 text-xs text-amber-700">No se pudo leer la lista de tiendas de Chedraui para este código postal.</p>
                @endif
            </div>
        @endif
        <button class="btn btn-secondary w-full">Guardar</button>
    </form>
    </div>
</div>
@endsection
