@extends('layouts.app')
@section('title', 'Editar receta')

@section('content')
<a href="{{ route('recipes.show', $recipe) }}" class="link text-sm">← {{ $recipe->name }}</a>
<h1 class="mt-2 mb-6 text-3xl font-bold text-slate-900">Editar receta</h1>

<form method="POST" action="{{ route('recipes.update', $recipe) }}" enctype="multipart/form-data" class="card max-w-4xl space-y-5">
    @method('PUT')
    @include('recipes._form')
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('recipes.show', $recipe) }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>

<form method="POST" action="{{ route('recipes.destroy', $recipe) }}" class="mt-6 max-w-4xl" onsubmit="return confirm('¿Eliminar la receta? También se quita de los menús.')">
    @csrf @method('DELETE')
    <button class="btn btn-danger">Eliminar receta</button>
</form>
@endsection
