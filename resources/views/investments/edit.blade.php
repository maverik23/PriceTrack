@extends('layouts.app')
@section('title', 'Editar cuenta')

@section('content')
<a href="{{ route('investments.show', $account) }}" class="link text-sm">← {{ $account->name }}</a>
<h1 class="mt-2 mb-6 text-2xl font-semibold text-slate-900">Editar cuenta</h1>

<form method="POST" action="{{ route('investments.update', $account) }}" class="card max-w-3xl space-y-5">
    @method('PUT')
    @include('investments._form')
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('investments.show', $account) }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>

<form method="POST" action="{{ route('investments.destroy', $account) }}" class="mt-6 max-w-3xl"
      onsubmit="return confirm('¿Eliminar la cuenta con todos sus movimientos y valores? Si ya no la usas, mejor pon la fecha de cierre.')">
    @csrf @method('DELETE')
    <button class="btn btn-danger">Eliminar cuenta</button>
</form>
@endsection
