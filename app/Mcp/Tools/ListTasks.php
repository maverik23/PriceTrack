<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Task;
use App\Models\TaskList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Tareas del hogar. Por defecto las pendientes (vencidas, de hoy, próximas y sin fecha, en ese orden); con `status` se filtra, y `done` da las completadas en los últimos 7 días. También devuelve las listas de tareas con su id. Los recordatorios de mantenimiento de los bienes aparecen aquí, en la lista "Mantenimiento".')]
#[IsReadOnly]
class ListTasks extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'overdue', 'today', 'upcoming', 'undated', 'done'])],
            'list_id' => ['nullable', 'integer', 'exists:task_lists,id'],
        ], [], ['status' => 'estado', 'list_id' => 'lista']);
        $status = $data['status'] ?? 'pending';

        $query = Task::with('list')->when(isset($data['list_id']), fn ($q) => $q->where('task_list_id', $data['list_id']));

        $tasks = $status === 'done'
            ? $query->completed()->where('completed_at', '>=', now()->subDays(7))->orderByDesc('completed_at')->get()
            : $query->pending()->orderByRaw('due_on is null')->orderBy('due_on')->orderByDesc('priority')->orderBy('id')->get()
                ->when($status !== 'pending', fn ($all) => $all->filter(fn (Task $t) => $t->status() === $status)->values());

        return $this->json([
            'today' => today()->toDateString(),
            'tasks' => $tasks->map(fn (Task $t) => Present::task($t))->all(),
            'lists' => TaskList::withCount(['tasks' => fn ($q) => $q->pending()])->orderBy('name')->get()
                ->map(fn (TaskList $l) => ['id' => $l->id, 'name' => $l->name, 'pending' => $l->tasks_count])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['pending', 'overdue', 'today', 'upcoming', 'undated', 'done'])->description('pending por defecto.'),
            'list_id' => $schema->integer()->description('Solo las tareas de esta lista.'),
        ];
    }
}
