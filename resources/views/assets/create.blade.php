@extends('layouts.app')
@section('title', 'Nuevo bien')

@section('content')
<a href="{{ route('assets.index') }}" class="link text-sm">← Bienes</a>
<h1 class="mt-2 mb-6 text-3xl font-bold text-slate-900">Nuevo bien</h1>

<form method="POST" action="{{ route('assets.store') }}" class="card max-w-4xl space-y-5">
    @include('assets._form')
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('assets.index') }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>
@endsection
