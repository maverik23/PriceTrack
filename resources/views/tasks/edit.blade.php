@extends('layouts.app')
@section('title', 'Editar tarea')

@section('content')
<a href="{{ route('tasks.index', $task->task_list_id ? ['list' => $task->task_list_id] : []) }}" class="link text-sm">← Tareas</a>
<h1 class="mt-2 mb-6 text-3xl font-bold text-slate-900">Editar tarea</h1>

<form method="POST" action="{{ route('tasks.update', $task) }}" class="card max-w-3xl space-y-5">
    @csrf @method('PUT')
    <input type="hidden" name="redirect" value="{{ route('tasks.index', $task->task_list_id ? ['list' => $task->task_list_id] : [], false) }}">
    @include('tasks._fields', ['full' => true, 'autofocus' => true])
    @if ($task->isDone())
        <p class="text-sm text-slate-500">Hecha el {{ $task->completed_at->translatedFormat('d \d\e F Y, H:i') }}.</p>
    @endif
    @if ($task->repeatedFrom)
        <p class="text-xs text-slate-500">Se creó al completar la anterior ({{ $task->repeatedFrom->completed_at?->translatedFormat('d M Y') }}).</p>
    @endif
    <div class="flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="{{ route('tasks.index') }}" class="btn btn-secondary">Cancelar</a>
    </div>
</form>

<div class="mt-6 flex max-w-3xl flex-wrap gap-2">
    <form method="POST" action="{{ route('tasks.toggle', $task) }}">
        @csrf
        <button class="btn btn-secondary">{{ $task->isDone() ? 'Marcar como pendiente' : 'Marcar como hecha' }}</button>
    </form>
    <form method="POST" action="{{ route('tasks.destroy', $task) }}" onsubmit="return confirm('¿Eliminar la tarea?')">
        @csrf @method('DELETE')
        <button class="btn btn-danger">Eliminar tarea</button>
    </form>
</div>
@endsection
