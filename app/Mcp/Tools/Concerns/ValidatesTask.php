<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;

/** Campos de una tarea, con las mismas reglas que el formulario (TaskController). */
trait ValidatesTask
{
    /** @return array<string, mixed> */
    protected function taskData(Request $request, bool $partial = false): array
    {
        $data = $request->validate([
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'list_id' => ['nullable', 'integer', 'exists:task_lists,id'],
            'due_on' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(array_keys(Task::PRIORITIES))],
            'repeat_every' => ['nullable', 'integer', 'min:1', 'max:999', 'required_with:repeat_unit'],
            'repeat_unit' => ['nullable', Rule::in(array_keys(Task::REPEAT_UNITS)), 'required_with:repeat_every'],
        ], [], [
            'title' => 'tarea', 'list_id' => 'lista', 'due_on' => 'fecha', 'priority' => 'prioridad',
            'repeat_every' => 'cada cuánto', 'repeat_unit' => 'unidad de repetición',
        ]);

        if (array_key_exists('list_id', $data)) {
            $data['task_list_id'] = $data['list_id'];
            unset($data['list_id']);
        }
        if (isset($data['priority'])) {
            $data['priority'] = (int) $data['priority'];
        } else {
            unset($data['priority']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    protected function taskSchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Qué hay que hacer.'),
            'notes' => $schema->string(),
            'list_id' => $schema->integer()->description('Id de la lista de tareas (de list-tasks); sin lista queda en "General".'),
            'due_on' => $schema->string()->description('Fecha límite, AAAA-MM-DD.'),
            'priority' => $schema->integer()->enum(array_keys(Task::PRIORITIES))->description('1 baja, 2 normal (por defecto), 3 alta.'),
            'repeat_every' => $schema->integer()->description('Se repite cada N… (junto con repeat_unit).'),
            'repeat_unit' => $schema->string()->enum(array_keys(Task::REPEAT_UNITS))->description('day, week, month o year.'),
        ];
    }
}
