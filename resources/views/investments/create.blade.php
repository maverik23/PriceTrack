@extends('layouts.app')
@section('title', 'Nueva cuenta de inversión')

@section('content')
<a href="{{ route('investments.index') }}" class="link text-sm">← Inversiones</a>
<h1 class="mt-2 mb-6 text-3xl font-bold text-slate-900">Nueva cuenta de inversión</h1>

<form method="POST" action="{{ route('investments.store') }}" class="card max-w-3xl space-y-5">
    @include('investments._form')
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('investments.index') }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>
@endsection
