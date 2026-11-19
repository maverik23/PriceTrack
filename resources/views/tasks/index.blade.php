@extends('layouts.app')
@section('title', $list?->name ?? 'Tareas')

@section('content')
@php
    $current = $list ? (string) $list->id : ($general ? 'none' : '');
    $showList = ! $list && ! $general;
    $empty = collect($groups)->every(fn ($group) => $group[1]->isEmpty());
@endphp
<div id="tasks-results" data-live-results>
<div class="mb-6">
    <h1 class="text-3xl font-bold text-slate-900">{{ $list?->name ?? ($general ? 'General' : 'Tareas') }}</h1>
    <p class="text-sm text-slate-500">Las repetitivas crean la siguiente al completarlas, contando desde ese día.</p>
</div>

<nav class="mb-4 flex flex-wrap gap-2 text-sm lg:hidden" aria-label="Listas de tareas">
    @foreach ([['', 'Todas', $allCount], ['none', 'General', $generalCount]] as [$value, $label, $count])
        <a href="{{ route('tasks.index', $value === '' ? [] : ['list' => $value]) }}" class="badge px-3 py-1 {{ $current === $value ? 'badge-cheap' : 'badge-muted' }}">{{ $label }} · {{ $count }}</a>
    @endforeach
    @foreach ($lists as $option)
        <a href="{{ route('tasks.index', ['list' => $option->id]) }}" class="badge px-3 py-1 {{ $current === (string) $option->id ? 'badge-cheap' : 'badge-muted' }}">{{ $option->name }} · {{ $option->tasks_count }}</a>
    @endforeach
</nav>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-4">

    <div class="space-y-6 lg:col-span-3">
        <form method="POST" action="{{ route('tasks.store') }}" class="card" x-data="{ more: {{ $errors->any() ? 'true' : 'false' }} }">
            @csrf
            <div class="flex flex-col gap-2 sm:flex-row">
                <label class="sr-only" for="quick-title">Nueva tarea</label>
                <input id="quick-title" name="title" value="{{ old('title') }}" class="input" maxlength="200" required placeholder="Nueva tarea…">
                <input type="date" name="due_on" value="{{ old('due_on') }}" class="input sm:w-44" aria-label="Fecha">
                <div class="flex gap-2">
                    <button class="btn btn-primary flex-1">Agregar</button>
                    <button type="button" class="btn btn-ghost" @click="more = ! more" :aria-expanded="more">Más</button>
                </div>
            </div>
            <div x-show="more" x-cloak class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                    <label class="label" for="quick-list">Lista</label>
                    <select id="quick-list" name="task_list_id" class="input">
                        <option value="">General</option>
                        @foreach ($lists as $option)
                            <option value="{{ $option->id }}" @selected((string) old('task_list_id', $list?->id) === (string) $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="quick-priority">Prioridad</label>
                    <select id="quick-priority" name="priority" class="input">
                        @foreach (\App\Models\Task::PRIORITIES as $value => $label)
                            <option value="{{ $value }}" @selected((int) old('priority', 2) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <span class="label">Repetir cada</span>
                    <div class="flex gap-2">
                        <input name="repeat_every" type="number" min="1" max="999" value="{{ old('repeat_every') }}" class="input w-20" aria-label="Cada cuánto">
                        <select name="repeat_unit" class="input" aria-label="Unidad de repetición">
                            <option value="">No se repite</option>
                            @foreach (\App\Models\Task::REPEAT_UNITS as $value => [$one, $many])
                                <option value="{{ $value }}" @selected(old('repeat_unit') === $value)>{{ $many }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </form>

        @if ($empty)
            <div class="card py-10 text-center text-sm text-slate-500">No hay tareas pendientes{{ $list || $general ? ' en esta lista' : '' }}. 🎉</div>
        @endif

        @foreach ($groups as $key => [$title, $tasks])
            @continue($tasks->isEmpty())
            <section class="card p-0" aria-labelledby="group-{{ $key }}">
                <h2 id="group-{{ $key }}" class="card-title flex items-center gap-2 border-b border-slate-100 px-4 py-3 {{ $key === 'overdue' ? 'text-red-700' : '' }}">
                    {{ $title }} <span class="badge {{ $key === 'overdue' ? 'badge-up' : 'badge-muted' }}">{{ $tasks->count() }}</span>
                </h2>
                <ul class="divide-y divide-slate-100">
                    @foreach ($tasks as $task)
                        @include('tasks._row')
                    @endforeach
                </ul>
            </section>
        @endforeach

        @if ($done->isNotEmpty())
            <details class="card p-0">
                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-slate-600">Hechas en los últimos 7 días ({{ $done->count() }})</summary>
                <ul class="divide-y divide-slate-100 border-t border-slate-100">
                    @foreach ($done as $task)
                        @include('tasks._row')
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
    <aside class="space-y-4">
        <nav class="card p-2 text-sm" aria-label="Listas de tareas">
            @foreach ([['', 'Todas', $allCount], ['none', 'General', $generalCount]] as [$value, $label, $count])
                <a href="{{ route('tasks.index', $value === '' ? [] : ['list' => $value]) }}"
                   class="flex items-center justify-between rounded-md px-3 py-1.5 {{ $current === $value ? 'bg-emerald-50 font-semibold text-emerald-700' : 'text-slate-600 hover:bg-slate-100' }}">
                    {{ $label }} <span class="text-xs tabular-nums text-slate-400">{{ $count }}</span>
                </a>
            @endforeach
            @if ($lists->isNotEmpty())
                <div class="my-1 h-px bg-slate-100"></div>
            @endif
            @foreach ($lists as $option)
                <a href="{{ route('tasks.index', ['list' => $option->id]) }}"
                   class="flex items-center justify-between rounded-md px-3 py-1.5 {{ $current === (string) $option->id ? 'bg-emerald-50 font-semibold text-emerald-700' : 'text-slate-600 hover:bg-slate-100' }}">
                    <span class="truncate">{{ $option->name }}</span> <span class="text-xs tabular-nums text-slate-400">{{ $option->tasks_count }}</span>
                </a>
            @endforeach
        </nav>

        <form method="POST" action="{{ route('task-lists.store') }}" class="card space-y-2">
            @csrf
            <label class="label" for="new-list">Nueva lista</label>
            <div class="flex gap-2">
                <input id="new-list" name="name" class="input" maxlength="80" placeholder="Ej. Casa, Auto, Jardín" required>
                <button class="btn btn-secondary">Crear</button>
            </div>
        </form>

        @if ($list)
            <details class="card text-sm">
                <summary class="cursor-pointer font-medium text-slate-700">Renombrar o eliminar «{{ $list->name }}»</summary>
                <form method="POST" action="{{ route('task-lists.update', $list) }}" class="mt-3 flex gap-2">
                    @csrf @method('PUT')
                    <input name="name" value="{{ $list->name }}" class="input" maxlength="80" required aria-label="Nombre de la lista">
                    <button class="btn btn-secondary">Guardar</button>
                </form>
                <form method="POST" action="{{ route('task-lists.destroy', $list) }}" class="mt-2"
                      onsubmit="return confirm('¿Eliminar la lista? Sus tareas pasan a General.')">
                    @csrf @method('DELETE')
                    <button class="btn btn-danger btn-sm">Eliminar lista</button>
                </form>
            </details>
        @endif
    </aside>
</div>
</div>
@endsection
