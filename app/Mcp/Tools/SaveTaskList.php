<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\TaskList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Crea una lista de tareas ("Casa", "Auto", "Jardín") o, con `id`, la renombra. Las tareas se ponen en una lista con `list_id` en create-task / update-task.')]
class SaveTaskList extends Tool
{
    public function handle(Request $request): Response
    {
        $request->validate(['id' => ['nullable', 'integer']]);
        $list = $request->get('id') ? $this->find(TaskList::class, $request->get('id'), 'una lista de tareas') : new TaskList;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('task_lists', 'name')->ignore($list->id)],
        ], [], ['name' => 'nombre de la lista']);

        $creating = ! $list->exists;
        $list->fill($data)->save();

        return $this->json(['saved' => $creating ? 'created' : 'updated', 'list' => ['id' => $list->id, 'name' => $list->name]]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Para renombrar una existente (de list-tasks). Sin él se crea.'),
            'name' => $schema->string()->description('Nombre de la lista.')->required(),
        ];
    }
}
