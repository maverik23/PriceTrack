<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\TaskList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Borra una lista de tareas (`id`, de list-tasks). Sus tareas no se borran: pasan a "General". Confirma con la persona antes.')]
#[IsDestructive]
class DeleteTaskList extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate(['id' => ['required', 'integer']]);
        $list = $this->find(TaskList::class, $data['id'], 'una lista de tareas');
        $moved = $list->tasks()->count();
        $list->delete();

        return $this->json(['deleted' => $list->name, 'tasks_moved_to_general' => $moved]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->description('Id de la lista.')->required()];
    }
}
