@extends('layouts.app')
@section('title', 'Historial de precios')

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Historial de precios</h1>
        <p class="text-sm text-slate-500">Cada precio queda guardado con su fecha y proveedor.</p>
    </div>
    <a href="{{ route('prices.create') }}" class="btn btn-primary">+ Capturar precio</a>
</div>

<form method="GET" class="card mb-4 grid grid-cols-1 gap-3 sm:grid-cols-5 sm:items-end">
    <div>
        <label class="label" for="f-product">Artículo</label>
        <select id="f-product" name="product_id" class="input">
            <option value="">Todos</option>
            @foreach ($products as $product)
                <option value="{{ $product->id }}" @selected((int) request('product_id') === $product->id)>{{ $product->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="label" for="f-provider">Proveedor</label>
        <select id="f-provider" name="provider_id" class="input">
            <option value="">Todos</option>
            @foreach ($providers as $provider)
                <option value="{{ $provider->id }}" @selected((int) request('provider_id') === $provider->id)>{{ $provider->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="label" for="f-from">Desde</label>
        <input id="f-from" type="date" name="from" value="{{ request('from') }}" class="input">
    </div>
    <div>
        <label class="label" for="f-to">Hasta</label>
        <input id="f-to" type="date" name="to" value="{{ request('to') }}" class="input">
    </div>
    <div class="flex gap-2">
        <button class="btn btn-primary">Filtrar</button>
        <a href="{{ route('prices.index') }}" class="btn btn-ghost">Limpiar</a>
    </div>
</form>

<div class="card overflow-x-auto p-0">
    <table class="table">
        <thead>
        <tr><th>Fecha</th><th>Artículo</th><th>Proveedor</th><th>Presentación</th><th class="text-right">Precio</th><th class="text-right">Por unidad</th><th>Notas</th><th></th></tr>
        </thead>
        <tbody>
        @forelse ($prices as $price)
            <tr>
                <td class="whitespace-nowrap text-slate-600">{{ $price->observed_on->translatedFormat('d M Y') }}</td>
                <td><a class="font-medium hover:text-emerald-700" href="{{ route('products.show', $price->product) }}">{{ $price->product->name }}</a></td>
                <td>{{ $price->provider->name }}</td>
                <td class="text-slate-600">{{ $price->presentationLabel() }}</td>
                <td class="text-right tabular-nums">{{ money($price->price) }}</td>
                <td class="text-right font-medium tabular-nums">{{ money($price->comparePrice()) }}{{ $price->compareLabel() }}</td>
                <td class="max-w-48 truncate text-xs text-slate-500">
                    {{ $price->notes }}
                    @if ($price->shoppingList)<a class="link" href="{{ route('lists.show', $price->shoppingList) }}">{{ $price->shoppingList->name }}</a>@endif
                </td>
                <td class="text-right whitespace-nowrap">
                    <a href="{{ route('prices.edit', $price) }}" class="btn btn-ghost btn-sm">Editar</a>
                    <form method="POST" action="{{ route('prices.destroy', $price) }}" class="inline" onsubmit="return confirm('¿Eliminar este precio?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-danger btn-sm">×</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="py-10 text-center text-slate-500">Sin precios para estos filtros.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $prices->links() }}</div>
@endsection
