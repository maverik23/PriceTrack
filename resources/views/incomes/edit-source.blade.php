@extends('layouts.app')
@section('title', 'Editar ingreso recurrente')

@section('content')
<a href="{{ route('incomes.index') }}" class="link text-sm">← Ingresos</a>
<h1 class="mt-2 mb-6 text-3xl font-bold text-slate-900">Editar {{ $source->name }}</h1>

<form method="POST" action="{{ route('incomes.sources.update', $source) }}" class="card max-w-3xl space-y-4">
    @csrf @method('PUT')
    @include('incomes._source-fields')
    <input type="hidden" name="active" value="0">
    <label class="flex items-center gap-2 text-sm text-slate-700">
        <input type="checkbox" name="active" value="1" class="size-4" @checked(old('active', $source->active))> Activo (si lo pausas deja de contar en lo esperado)
    </label>
    <div class="flex flex-wrap gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('incomes.index') }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>

<form method="POST" action="{{ route('incomes.sources.destroy', $source) }}" class="mt-4" onsubmit="return confirm('¿Eliminar este ingreso recurrente? Lo ya recibido se conserva.')">
    @csrf @method('DELETE')
    <button class="btn btn-danger btn-sm">Eliminar</button>
</form>
@endsection
