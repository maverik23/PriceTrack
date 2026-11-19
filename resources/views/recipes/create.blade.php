@extends('layouts.app')
@section('title', 'Nueva receta')

@section('content')
<a href="{{ route('recipes.index') }}" class="link text-sm">← Recetas</a>
<h1 class="mt-2 mb-6 text-2xl font-semibold text-slate-900">Nueva receta</h1>

<form method="POST" action="{{ route('recipes.store') }}" class="card max-w-4xl space-y-5">
    @include('recipes._form')
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar receta</button>
        <a href="{{ route('recipes.index') }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>
@endsection
