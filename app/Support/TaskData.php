<?php

namespace App\Support;

use App\Models\Task;
use App\Models\TaskList;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Campos de una tarea y de una lista de tareas: los usan el formulario (TaskController,
 * TaskListController) y la API de la app. Las herramientas MCP tienen los suyos en
 * Mcp\Tools\Concerns\ValidatesTask (allí la lista se llama `list_id`): si cambias unos, cambia los otros.
 */
class TaskData
{
    /** @return array<string, mixed> los campos validados y normalizados */
    public static function validate(array $input): array
    {
        $data = Validator::validate($input, [
            'title' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'task_list_id' => ['nullable', 'exists:task_lists,id'],
            'due_on' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(array_keys(Task::PRIORITIES))],
            'repeat_every' => ['nullable', 'integer', 'min:1', 'max:999', 'required_with:repeat_unit'],
            'repeat_unit' => ['nullable', Rule::in(array_keys(Task::REPEAT_UNITS)), 'required_with:repeat_every'],
        ], [], [
            'title' => 'tarea',
            'task_list_id' => 'lista',
            'due_on' => 'fecha',
            'priority' => 'prioridad',
            'repeat_every' => 'cada cuánto',
            'repeat_unit' => 'unidad de repetición',
        ]);

        $data['priority'] = (int) ($data['priority'] ?? 2);
        if (empty($data['repeat_every']) || empty($data['repeat_unit'])) {
            $data['repeat_every'] = null;
            $data['repeat_unit'] = null;
        }

        return $data;
    }

    /** La tarea como entrada (para cambiar solo algunos campos). @return array<string, mixed> */
    public static function toInput(Task $task): array
    {
        return $task->only(['title', 'notes', 'task_list_id', 'priority', 'repeat_every', 'repeat_unit'])
            + ['due_on' => $task->due_on?->toDateString()];
    }

    /** El nombre de una lista de tareas (único). @return array{name: string} */
    public static function validateList(array $input, ?TaskList $list = null): array
    {
        return Validator::validate($input, [
            'name' => ['required', 'string', 'max:80', Rule::unique('task_lists', 'name')->ignore($list)],
        ], [], ['name' => 'nombre de la lista']);
    }
}
