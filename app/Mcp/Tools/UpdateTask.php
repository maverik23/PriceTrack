<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Mcp\Tools\Concerns\ValidatesTask;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Cambia una tarea: solo se tocan los campos que se mandan. Para quitar la fecha, la lista o la repetición manda null en ese campo (repeat_every y repeat_unit juntos). Para marcarla como hecha usa complete-task.')]
class UpdateTask extends Tool
{
    use ValidatesTask;

    public function handle(Request $request): Response
    {
        $task = $this->find(Task::class, $request->get('task_id'), 'una tarea');
        $task->update($this->taskData($request, partial: true));

        return $this->json(['updated' => Present::task($task->load('list'))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('Id de la tarea (de list-tasks).')->required(),
            ...$this->taskSchema($schema),
        ];
    }
}
