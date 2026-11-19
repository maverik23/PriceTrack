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

#[Description('Crea una tarea del hogar, con fecha, prioridad y repetición opcionales. En las repetitivas, la siguiente fecha se cuenta desde el día en que se completa.')]
class CreateTask extends Tool
{
    use ValidatesTask;

    public function handle(Request $request): Response
    {
        $task = Task::create($this->taskData($request));

        return $this->json(['created' => Present::task($task->refresh()->load('list'))]);
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = $this->taskSchema($schema);
        $fields['title']->required();

        return $fields;
    }
}
