@extends('layouts.app')
@section('title', 'Editar proveedor')

@section('content')
<a href="{{ route('providers.index') }}" class="link text-sm">← Proveedores</a>
<h1 class="mt-2 mb-6 text-2xl font-semibold text-slate-900">Editar {{ $provider->name }}</h1>

<form method="POST" action="{{ route('providers.update', $provider) }}" class="card max-w-lg space-y-3">
    @csrf @method('PUT')
    <div>
        <label class="label" for="name">Nombre</label>
        <input id="name" name="name" value="{{ old('name', $provider->name) }}" class="input" required>
    </div>
    <div>
        <label class="label" for="notes">Notas</label>
        <input id="notes" name="notes" value="{{ old('notes', $provider->notes) }}" class="input">
    </div>
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('providers.index') }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>
@endsection
