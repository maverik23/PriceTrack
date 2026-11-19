<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Marca una tarea como hecha (o la reabre con `done`: false). Si se repite, crea la siguiente contando desde hoy. Si es el recordatorio de un mantenimiento, completarla registra el servicio del bien y crea el siguiente recordatorio; reabrirla lo deshace.')]
class CompleteTask extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate(['task_id' => ['required', 'integer'], 'done' => ['nullable', 'boolean']]);
        $task = $this->find(Task::class, $data['task_id'], 'una tarea', ['list']);
        $done = $data['done'] ?? true;

        if ($done === $task->isDone()) {
            return $this->json(['unchanged' => Present::task($task)]);
        }

        $next = null;
        if ($done) {
            $next = $task->complete();
        } else {
            $task->reopen();
        }

        return $this->json([
            'task' => Present::task($task->refresh()->load('list')),
            'next' => $next ? Present::task($next->load('list')) : null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('Id de la tarea (de list-tasks).')->required(),
            'done' => $schema->boolean()->description('true (por defecto) la completa; false la vuelve pendiente.'),
        ];
    }
}
