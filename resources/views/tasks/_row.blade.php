{{-- Un renglón de tarea con su botón de hecha. $task, $showList --}}
@php($status = $task->status())
<li class="flex items-start gap-3 px-4 py-3">
    <form method="POST" action="{{ route('tasks.toggle', $task) }}" class="pt-0.5">
        @csrf
        <button class="grid size-5 place-items-center rounded-full ring-2 {{ $task->isDone() ? 'bg-emerald-600 text-white ring-emerald-600' : 'ring-slate-300 hover:ring-emerald-500' }}"
                title="{{ $task->isDone() ? 'Marcar como pendiente' : 'Marcar como hecha' }}"
                aria-label="{{ $task->isDone() ? 'Marcar como pendiente' : 'Marcar como hecha' }}: {{ $task->title }}">
            @if ($task->isDone())
                <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M5 10.5l3 3 7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
            @endif
        </button>
    </form>
    <div class="min-w-0 flex-1">
        <a href="{{ route('tasks.edit', $task) }}" class="font-medium break-words {{ $task->isDone() ? 'text-slate-400 line-through' : 'text-slate-900 hover:text-emerald-700' }}">{{ $task->title }}</a>
        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
            @if ($task->isDone())
                <span>Hecha {{ $task->completed_at->translatedFormat('D d M, H:i') }}</span>
            @elseif ($task->due_on)
                <span class="{{ $status === 'overdue' ? 'font-semibold text-red-600' : ($status === 'today' ? 'font-semibold text-emerald-700' : '') }}">
                    {{ $status === 'today' ? 'Hoy' : ucfirst($task->due_on->translatedFormat('D d M')) }}@if ($status === 'overdue') · hace {{ $task->due_on->diffInDays(today()) }} {{ $task->due_on->diffInDays(today()) == 1 ? 'día' : 'días' }}@endif
                </span>
            @endif
            @if ($task->priority === 3)<span class="badge badge-up">Alta</span>@elseif ($task->priority === 1)<span class="badge badge-muted">Baja</span>@endif
            @if ($task->isRecurring())<span title="Se repite">↻ {{ $task->repeatLabel() }}</span>@endif
            @if (($showList ?? false) && $task->list)<span class="badge badge-muted">{{ $task->list->name }}</span>@endif
            @if ($task->taskable && method_exists($task->taskable, 'taskLink'))
                <a href="{{ $task->taskable->taskLink() }}" class="link">{{ $task->taskable->name }}</a>
            @endif
        </div>
        @if ($task->notes && ! $task->isDone())
            <p class="mt-1 line-clamp-2 text-xs text-slate-500">{{ $task->notes }}</p>
        @endif
    </div>
</li>
