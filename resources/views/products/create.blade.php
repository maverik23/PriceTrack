@extends('layouts.app')
@section('title', 'Nuevo artículo')

@section('content')
<a href="{{ $addToList ? route('lists.show', $addToList) : route('products.index') }}" class="link text-sm">← Volver</a>
<h1 class="mt-2 mb-6 text-2xl font-semibold text-slate-900">Nuevo artículo</h1>

<form method="POST" action="{{ route('products.store') }}" class="card max-w-2xl space-y-4">
    @include('products._form')
    @if ($addToList)
        <input type="hidden" name="add_to_list" value="{{ $addToList }}">
        <p class="text-xs text-slate-500">Se agregará automáticamente a tu lista.</p>
    @endif
    <button class="btn btn-primary">Crear artículo</button>
</form>
@endsection
