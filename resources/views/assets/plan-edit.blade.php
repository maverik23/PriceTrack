@extends('layouts.app')
@section('title', 'Editar plan')

@section('content')
<a href="{{ route('assets.show', $asset) }}" class="link text-sm">← {{ $asset->name }}</a>
<h1 class="mt-2 mb-6 text-2xl font-semibold text-slate-900">Editar plan de mantenimiento</h1>

<form method="POST" action="{{ route('assets.plans.update', [$asset, $plan]) }}" class="card max-w-2xl space-y-4">
    @csrf @method('PUT')
    <div>
        <label class="label" for="name">Qué se hace</label>
        <input id="name" name="name" value="{{ old('name', $plan->name) }}" class="input" maxlength="150" required>
    </div>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <span class="label">Cada (tiempo)</span>
            <div class="flex gap-2">
                <input name="every" type="number" min="1" max="999" value="{{ old('every', $plan->every) }}" class="input w-20" aria-label="Cada cuánto tiempo">
                <select name="every_unit" class="input" aria-label="Unidad de tiempo">
                    <option value="">—</option>
                    @foreach ($units as $value => [$one, $many])
                        <option value="{{ $value }}" @selected(old('every_unit', $plan->every_unit) === $value)>{{ $many }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        @if ($asset->meter_unit)
            <div>
                <label class="label" for="every_meter">o cada ({{ $asset->meter_unit }})</label>
                <input id="every_meter" name="every_meter" type="number" step="0.1" min="0.1" value="{{ old('every_meter', num($plan->every_meter)) }}" class="input">
            </div>
        @endif
        <div>
            <label class="label" for="estimated_cost">Costo estimado</label>
            <input id="estimated_cost" name="estimated_cost" type="number" step="0.01" min="0" value="{{ old('estimated_cost', num($plan->estimated_cost)) }}" class="input">
        </div>
        <div>
            <label class="label" for="notes">Notas</label>
            <input id="notes" name="notes" value="{{ old('notes', $plan->notes) }}" class="input" maxlength="255">
        </div>
    </div>
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('assets.show', $asset) }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>

<form method="POST" action="{{ route('assets.plans.destroy', [$asset, $plan]) }}" class="mt-6" onsubmit="return confirm('¿Eliminar el plan? Los servicios hechos se conservan.')">
    @csrf @method('DELETE')
    <button class="btn btn-danger">Eliminar plan</button>
</form>
@endsection
