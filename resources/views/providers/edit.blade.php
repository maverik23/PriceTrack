@extends('layouts.app')
@section('title', 'Editar proveedor')

@section('content')
<a href="{{ route('providers.index') }}" class="link text-sm">← Proveedores</a>
<h1 class="mt-2 mb-6 text-3xl font-bold text-slate-900">Editar {{ $provider->name }}</h1>

<form method="POST" action="{{ route('providers.update', $provider) }}" class="card max-w-lg space-y-3">
    @csrf @method('PUT')
    @include('providers._form')
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('providers.index') }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>
@endsection
