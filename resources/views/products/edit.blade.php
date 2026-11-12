@extends('layouts.app')
@section('title', 'Editar artículo')

@section('content')
<a href="{{ route('products.show', $product) }}" class="link text-sm">← {{ $product->name }}</a>
<h1 class="mt-2 mb-6 text-2xl font-semibold text-slate-900">Editar artículo</h1>

<form method="POST" action="{{ route('products.update', $product) }}" class="card max-w-2xl space-y-4">
    @method('PUT')
    @include('products._form')
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('products.show', $product) }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>
@endsection
