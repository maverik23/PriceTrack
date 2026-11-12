@extends('layouts.app')
@section('title', 'Nueva lista')

@section('content')
<a href="{{ route('lists.index') }}" class="link text-sm">← Listas</a>
<h1 class="mt-2 mb-6 text-2xl font-semibold text-slate-900">Nueva lista de compra</h1>

<form method="POST" action="{{ route('lists.store') }}" class="card max-w-xl space-y-4">
    @csrf
    <div>
        <label class="label" for="name">Nombre</label>
        <input id="name" name="name" value="{{ old('name', $list->name) }}" class="input" required>
    </div>
    <div>
        <label class="label" for="shopped_on">Fecha de compra</label>
        <input id="shopped_on" name="shopped_on" type="date" value="{{ old('shopped_on', $list->shopped_on->format('Y-m-d')) }}" class="input" required>
        <p class="mt-1 text-xs text-slate-500">Esta fecha se usa para el reporte de gasto semanal / mensual / anual.</p>
    </div>
    <div>
        <label class="label" for="copy_from">Copiar artículos de otra lista (opcional)</label>
        <select id="copy_from" name="copy_from" class="input">
            <option value="">— Empezar vacía —</option>
            @foreach ($previous as $prev)
                <option value="{{ $prev->id }}" @selected((int) old('copy_from') === $prev->id)>{{ $prev->name }} ({{ $prev->items_count }} artículos, {{ $prev->shopped_on->translatedFormat('d M Y') }})</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="label" for="notes">Notas</label>
        <input id="notes" name="notes" value="{{ old('notes') }}" class="input">
    </div>
    <button class="btn btn-primary">Crear lista</button>
</form>
@endsection
