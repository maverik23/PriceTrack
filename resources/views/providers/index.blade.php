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
                <th>Sucursal</th>
                <th>Notas</th>
                <th class="text-right">Precios</th>
                <th>Último registro</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($providers as $provider)
                <tr>
                    <td class="font-medium text-slate-900">{{ $provider->name }} @if (! $provider->groceries)<span class="badge-muted ml-1" title="Su gasto no cuenta en el del súper">No es súper</span>@endif</td>
                    <td class="text-sm">
                        @if ($provider->chain)
                            <span class="text-slate-700">{{ $provider->branchLabel() ?? 'Sin elegir' }}</span>
                            <span class="block text-xs text-slate-500">{{ $provider->chainName() }}</span>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
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
                <tr><td colspan="6" class="py-8 text-center text-slate-500">Aún no hay proveedores. Agrega el primero →</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="space-y-6">
    <form method="POST" action="{{ route('providers.store') }}" class="card space-y-3">
        @csrf
        <h2 class="card-title">Nuevo proveedor</h2>
        @include('providers._form', ['provider' => new \App\Models\Provider])
        <button class="btn btn-primary w-full">Agregar</button>
    </form>

    {{-- Para pedir a las tiendas en línea el precio de tu zona --}}
    <form method="POST" action="{{ route('settings.update') }}" class="card space-y-3">
        @csrf @method('PUT')
        <h2 class="card-title">Código postal</h2>
        <div>
            <label class="label" for="postal_code">Para consultar precios en línea</label>
            <input id="postal_code" name="postal_code" value="{{ old('postal_code', $postalCode) }}" class="input" inputmode="numeric" maxlength="5" placeholder="Ej. 06600">
            @error('postal_code') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-slate-500">Con él se buscan las sucursales de cada proveedor (se eligen al agregarlo o editarlo) y se pide el precio de tu zona.</p>
        </div>
        <button class="btn btn-secondary w-full">Guardar</button>
    </form>
    </div>
</div>
@endsection
