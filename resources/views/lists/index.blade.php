@extends('layouts.app')
@section('title', 'Listas de compra')

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Listas de compra</h1>
        <p class="text-sm text-slate-500">Agrega artículos, captura precios por proveedor y marca dónde compraste.</p>
    </div>
    <a href="{{ route('lists.create') }}" class="btn btn-primary">+ Nueva lista</a>
</div>

<div class="card overflow-x-auto p-0">
    <table class="table">
        <thead>
        <tr><th>Fecha</th><th>Lista</th><th class="text-right">Artículos</th><th class="text-right">Comprados</th><th class="text-right">Total comprado</th><th class="text-right">Estimado</th><th></th></tr>
        </thead>
        <tbody>
        @forelse ($lists as $list)
            <tr>
                <td class="whitespace-nowrap text-slate-600">{{ $list->shopped_on->translatedFormat('D d M Y') }}</td>
                <td><a href="{{ route('lists.show', $list) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $list->name }}</a></td>
                <td class="text-right tabular-nums">{{ $list->items->count() }}</td>
                <td class="text-right tabular-nums">
                    <span class="{{ $list->purchasedCount() === $list->items->count() && $list->items->isNotEmpty() ? 'text-emerald-700 font-semibold' : 'text-slate-500' }}">{{ $list->purchasedCount() }}</span>
                </td>
                <td class="text-right font-semibold tabular-nums">{{ money($list->total()) }}</td>
                <td class="text-right tabular-nums text-slate-500">{{ money($list->estimatedTotal()) }}</td>
                <td class="text-right"><a href="{{ route('lists.show', $list) }}" class="btn btn-ghost btn-sm">Abrir →</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-10 text-center text-slate-500">No hay listas todavía. <a class="link" href="{{ route('lists.create') }}">Crea tu primera lista</a>.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $lists->links() }}</div>
@endsection
