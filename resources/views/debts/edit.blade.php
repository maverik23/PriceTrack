@extends('layouts.app')
@section('title', 'Editar deuda')

@section('content')
<a href="{{ route('debts.show', $debt) }}" class="link text-sm">← {{ $debt->name }}</a>
<h1 class="mt-2 mb-6 text-2xl font-semibold text-slate-900">Editar deuda</h1>

<form method="POST" action="{{ route('debts.update', $debt) }}" class="card max-w-3xl space-y-5">
    @method('PUT')
    @include('debts._form')
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('debts.show', $debt) }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>

<form method="POST" action="{{ route('debts.destroy', $debt) }}" class="mt-6 max-w-3xl"
      onsubmit="return confirm('¿Eliminar la deuda con sus saldos? Los pagos se conservan sin deuda. Si ya la pagaste, mejor pon la fecha en que se liquidó.')">
    @csrf @method('DELETE')
    <button class="btn btn-danger">Eliminar deuda</button>
</form>
@endsection
