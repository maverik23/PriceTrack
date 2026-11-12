@extends('layouts.app')
@section('title', 'Capturar precio')

@section('content')
<h1 class="mb-6 text-2xl font-semibold text-slate-900">Capturar precio</h1>

<form method="POST" action="{{ route('prices.store') }}" class="card max-w-2xl space-y-4">
    @include('prices._form')
    <input type="hidden" name="redirect" value="{{ route('prices.create', [], false) }}">
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar y capturar otro</button>
        <a href="{{ route('prices.index') }}" class="btn btn-secondary">Ver historial</a>
    </div>
</form>
@endsection
