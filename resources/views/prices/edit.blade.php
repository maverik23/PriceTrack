@extends('layouts.app')
@section('title', 'Editar precio')

@section('content')
<a href="{{ route('products.show', $price->product_id) }}" class="link text-sm">← {{ $price->product->name }}</a>
<h1 class="mt-2 mb-6 text-2xl font-semibold text-slate-900">Editar precio</h1>

<form method="POST" action="{{ route('prices.update', $price) }}" class="card max-w-2xl space-y-4">
    @method('PUT')
    @include('prices._form')
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('products.show', $price->product_id) }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>
@endsection
