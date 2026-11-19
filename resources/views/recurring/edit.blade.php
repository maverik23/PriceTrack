@extends('layouts.app')
@section('title', 'Editar pago')

@section('content')
<a href="{{ route('recurring.index') }}" class="link text-sm">← Pagos recurrentes</a>
<h1 class="mt-2 mb-6 text-2xl font-semibold text-slate-900">{{ $payment->name }}</h1>

<form method="POST" action="{{ route('recurring.update', $payment) }}" class="card max-w-3xl space-y-4">
    @csrf @method('PUT')
    @include('recurring._fields')
    <div>
        <label class="label" for="rp-notes">Notas</label>
        <input id="rp-notes" name="notes" value="{{ old('notes', $payment->notes) }}" class="input" maxlength="255">
    </div>
    <label class="flex items-center gap-2 text-sm text-slate-700">
        <input type="hidden" name="active" value="0">
        <input type="checkbox" name="active" value="1" class="size-4 rounded" @checked(old('active', $payment->active))>
        Activo (desmárcalo para pausarlo sin perder su historial)
    </label>
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('recurring.index') }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>

<section class="card mt-6 max-w-3xl p-0">
    <h2 class="card-title px-5 pt-5 pb-2">Historial</h2>
    <ul class="divide-y divide-slate-100 border-t border-slate-100 text-sm">
        @forelse ($payment->payments as $paid)
            <li class="flex items-center justify-between px-5 py-2">
                <span>{{ $paid->paid_on->translatedFormat('d M Y') }} @if ($paid->due_on && ! $paid->due_on->isSameDay($paid->paid_on))<span class="text-xs text-slate-500">(tocaba el {{ $paid->due_on->translatedFormat('d M') }})</span>@endif</span>
                <span class="tabular-nums">{{ money($paid->amount) }}</span>
            </li>
        @empty
            <li class="px-5 py-6 text-center text-slate-500">Sin pagos todavía.</li>
        @endforelse
    </ul>
</section>

<form method="POST" action="{{ route('recurring.destroy', $payment) }}" class="mt-6" onsubmit="return confirm('¿Eliminar este pago recurrente? Su historial se conserva.')">
    @csrf @method('DELETE')
    <button class="btn btn-danger">Eliminar</button>
</form>
@endsection
