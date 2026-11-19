@extends('layouts.app')
@section('title', 'Nueva deuda')

@section('content')
<a href="{{ route('debts.index') }}" class="link text-sm">← Deudas</a>
<h1 class="mt-2 mb-6 text-3xl font-bold text-slate-900">Nueva deuda</h1>

<form method="POST" action="{{ route('debts.store') }}" class="card max-w-3xl space-y-5">
    @include('debts._form')
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('debts.index') }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>
@endsection
