@extends('layouts.app')
@section('title', 'Listas de compra')

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-3xl font-bold text-slate-900">Listas de compra</h1>
        <p class="text-sm text-slate-500">Agrega artículos, captura precios por proveedor y marca dónde compraste.</p>
    </div>
    <a href="{{ route('lists.create') }}" class="btn btn-primary">+ Nueva lista</a>
</div>

<div id="lists-results" data-live-results>
<nav class="mb-4 flex flex-wrap gap-2" aria-label="Filtrar listas">
    @foreach (['pending' => 'Pendientes', 'done' => 'Compradas', 'all' => 'Todas'] as $key => $label)
        <a href="{{ route('lists.index', $key === 'pending' ? [] : ['status' => $key]) }}" class="chip {{ $status === $key ? 'is-active' : '' }}" @if ($status === $key) aria-current="page" @endif>
            {{ $label }} <span class="opacity-70">{{ $counts[$key] }}</span>
        </a>
    @endforeach
</nav>

<div class="card overflow-x-auto p-0">
    <table class="table">
        <thead>
        <tr><th>Fecha</th><th>Lista</th><th class="text-right">Artículos</th><th class="text-right">Comprados</th><th class="text-right">Total comprado</th><th class="text-right">Estimado</th><th></th></tr>
        </thead>
        <tbody>
        @forelse ($lists as $list)
            @php $done = $list->isDone(); @endphp
            <tr class="{{ $done ? 'is-done' : '' }}">
                <td class="whitespace-nowrap text-slate-600"><span class="done-strike">{{ $list->shopped_on->translatedFormat('D d M Y') }}</span></td>
                <td>
                    <a href="{{ route('lists.show', $list) }}" class="done-strike font-medium text-slate-900 hover:text-emerald-700">{{ $list->name }}</a>
                    @if ($done)
                        <span class="badge badge-cheap ml-1 align-middle">Comprada</span>
                    @endif
                </td>
                <td class="text-right tabular-nums">{{ $list->items->count() }}</td>
                <td class="text-right tabular-nums">
                    <span class="{{ $list->purchasedCount() === $list->items->count() && $list->items->isNotEmpty() ? 'text-emerald-700 font-semibold' : 'text-slate-500' }}">{{ $list->purchasedCount() }}</span>
                </td>
                <td class="text-right font-semibold tabular-nums">{{ money($list->total()) }}</td>
                <td class="text-right tabular-nums text-slate-500">{{ money($list->estimatedTotal()) }}</td>
                <td class="whitespace-nowrap text-right">
                    <form method="POST" action="{{ route('lists.done', $list) }}" class="inline" data-no-busy>
                        @csrf
                        <input type="hidden" name="done" value="{{ $done ? 0 : 1 }}">
                        <button class="btn btn-ghost btn-sm" title="{{ $done ? 'Volver a pendientes' : 'Marcar como comprada' }}">
                            <x-icon :name="$done ? 'rotate-ccw' : 'check'" class="size-4" />
                            <span class="hidden sm:inline">{{ $done ? 'Reabrir' : 'Comprada' }}</span>
                        </button>
                    </form>
                    <a href="{{ route('lists.show', $list) }}" class="btn btn-ghost btn-sm">Abrir →</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-10 text-center text-slate-500">
                @if ($status === 'pending' && $counts['all'])
                    No hay listas pendientes. <a class="link" href="{{ route('lists.create') }}">Crea una nueva</a> o <a class="link" href="{{ route('lists.index', ['status' => 'done']) }}">mira las compradas</a>.
                @elseif ($status === 'done')
                    Aún no hay listas compradas.
                @else
                    No hay listas todavía. <a class="link" href="{{ route('lists.create') }}">Crea tu primera lista</a>.
                @endif
            </td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $lists->links() }}</div>
</div>
@endsection
