@extends('layouts.app')
@section('title', 'Editar bien')

@section('content')
<a href="{{ route('assets.show', $asset) }}" class="link text-sm">← {{ $asset->name }}</a>
<h1 class="mt-2 mb-6 text-3xl font-bold text-slate-900">Editar bien</h1>

<form method="POST" action="{{ route('assets.update', $asset) }}" enctype="multipart/form-data" class="card max-w-4xl space-y-5">
    @method('PUT')
    @include('assets._form')
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('assets.show', $asset) }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>

<form method="POST" action="{{ route('assets.destroy', $asset) }}" class="mt-6 max-w-4xl"
      onsubmit="return confirm('¿Eliminar el bien con sus valores, lecturas y servicios? Si lo vendiste, mejor cambia su estado.')">
    @csrf @method('DELETE')
    <button class="btn btn-danger">Eliminar bien</button>
</form>
@endsection
