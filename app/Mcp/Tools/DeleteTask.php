<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Elimina una tarea para siempre. Si ya se hizo, usa complete-task en su lugar.')]
#[IsDestructive]
class DeleteTask extends Tool
{
    public function handle(Request $request): Response
    {
        $task = $this->find(Task::class, $request->get('task_id'), 'una tarea');
        $task->delete();

        return $this->json(['deleted' => $task->title]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->integer()->description('Id de la tarea (de list-tasks).')->required()];
    }
}
