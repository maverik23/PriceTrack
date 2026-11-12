@extends('layouts.app')
@section('title', 'Editar lista')

@section('content')
<a href="{{ route('lists.show', $list) }}" class="link text-sm">← {{ $list->name }}</a>
<h1 class="mt-2 mb-6 text-2xl font-semibold text-slate-900">Editar lista</h1>

<form method="POST" action="{{ route('lists.update', $list) }}" class="card max-w-xl space-y-4">
    @csrf @method('PUT')
    <div>
        <label class="label" for="name">Nombre</label>
        <input id="name" name="name" value="{{ old('name', $list->name) }}" class="input" required>
    </div>
    <div>
        <label class="label" for="shopped_on">Fecha de compra</label>
        <input id="shopped_on" name="shopped_on" type="date" value="{{ old('shopped_on', $list->shopped_on->format('Y-m-d')) }}" class="input" required>
    </div>
    <div>
        <label class="label" for="notes">Notas</label>
        <input id="notes" name="notes" value="{{ old('notes', $list->notes) }}" class="input">
    </div>
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('lists.show', $list) }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>

<form method="POST" action="{{ route('lists.destroy', $list) }}" class="mt-6 max-w-xl" onsubmit="return confirm('¿Eliminar la lista? Los precios capturados se conservan en el historial.')">
    @csrf @method('DELETE')
    <button class="btn btn-danger">Eliminar lista</button>
</form>
@endsection
