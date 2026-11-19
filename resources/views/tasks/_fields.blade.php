{{-- Campos de una tarea. $task (puede ser nueva), $lists, $idPrefix para no repetir ids --}}
@php($p = $idPrefix ?? 'task')
<div class="grid grid-cols-1 gap-3 sm:grid-cols-6">
    <div class="sm:col-span-6">
        <label class="label" for="{{ $p }}-title">Tarea</label>
        <input id="{{ $p }}-title" name="title" value="{{ old('title', $task->title) }}" class="input" maxlength="200" required
               placeholder="Ej. Cambiar filtro del agua" @if ($autofocus ?? false) autofocus @endif>
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="{{ $p }}-list">Lista</label>
        <select id="{{ $p }}-list" name="task_list_id" class="input">
            <option value="">General</option>
            @foreach ($lists as $option)
                <option value="{{ $option->id }}" @selected((string) old('task_list_id', $task->task_list_id) === (string) $option->id)>{{ $option->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="{{ $p }}-due">Fecha <span class="font-normal text-slate-400">opcional</span></label>
        <input id="{{ $p }}-due" name="due_on" type="date" value="{{ old('due_on', $task->due_on?->toDateString()) }}" class="input">
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="{{ $p }}-priority">Prioridad</label>
        <select id="{{ $p }}-priority" name="priority" class="input">
            @foreach (\App\Models\Task::PRIORITIES as $value => $label)
                <option value="{{ $value }}" @selected((int) old('priority', $task->priority ?? 2) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="sm:col-span-6">
        <span class="label">Repetir <span class="font-normal text-slate-400">(la siguiente se cuenta desde el día en que la completes)</span></span>
        <div class="flex items-center gap-2">
            <span class="text-sm text-slate-600">Cada</span>
            <input name="repeat_every" type="number" min="1" max="999" value="{{ old('repeat_every', $task->repeat_every) }}" class="input w-20" aria-label="Cada cuánto">
            <select name="repeat_unit" class="input w-36" aria-label="Unidad de repetición">
                <option value="">No se repite</option>
                @foreach (\App\Models\Task::REPEAT_UNITS as $value => [$one, $many])
                    <option value="{{ $value }}" @selected(old('repeat_unit', $task->repeat_unit) === $value)>{{ $many }}</option>
                @endforeach
            </select>
        </div>
    </div>
    @if ($full ?? false)
        <div class="sm:col-span-6">
            <label class="label" for="{{ $p }}-notes">Notas</label>
            <textarea id="{{ $p }}-notes" name="notes" rows="4" class="input">{{ old('notes', $task->notes) }}</textarea>
        </div>
    @endif
</div>
