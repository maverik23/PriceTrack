<?php

namespace App\Http\Controllers\Api;

use App\Api\Present;
use App\Models\Task;
use App\Models\TaskList;
use App\Support\TaskCalendar;
use App\Support\TaskData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Tareas para la app móvil: las reglas son las de la web (TaskData) y el calendario, TaskCalendar. */
class TaskController extends Controller
{
    /**
     * Pendientes en el orden de los grupos (vencidas, hoy, próximas, sin fecha), paginadas;
     * `list` (id o `none` = General) y `q`. Además los totales de cada grupo, lo hecho en la
     * última semana y las listas.
     */
    public function index(Request $request)
    {
        $scope = $this->scope($request);
        $today = today();

        $pending = Task::with('list', 'taskable')->pending()->tap($scope)
            ->orderByRaw('due_on is null')->orderBy('due_on')->orderByDesc('priority')->orderBy('id')
            ->paginate($this->perPage($request));
        $totals = Task::pending()->tap($scope)->get(['id', 'due_on', 'completed_at'])
            ->countBy(fn (Task $t) => $t->status($today));

        return $this->page($pending, fn (Task $t) => Present::task($t), [
            'today' => $today->toDateString(),
            'groups' => collect(['overdue' => 'Vencidas', 'today' => 'Hoy', 'upcoming' => 'Próximas', 'undated' => 'Sin fecha'])
                ->map(fn (string $title, string $key) => ['key' => $key, 'title' => $title, 'total' => (int) ($totals[$key] ?? 0)])->values(),
            'done' => Task::with('list', 'taskable')->completed()->tap($scope)
                ->where('completed_at', '>=', now()->subDays(7))
                ->orderByDesc('completed_at')->get()->map(fn (Task $t) => Present::task($t))->values(),
            ...self::lists(),
        ]);
    }

    /**
     * El calendario de un mes (`month=AAAA-MM`, semanas de lunes a domingo): cada día con sus
     * tareas, sus conteos y las fechas proyectadas de las repetitivas (no son tareas todavía).
     */
    public function calendar(Request $request)
    {
        $month = rescue(fn () => preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? CarbonImmutable::createFromFormat('!Y-m', $request->query('month'))
            : null, null, false) ?? CarbonImmutable::today();
        $calendar = TaskCalendar::month($month, $this->scope($request));

        return response()->json(['data' => [
            'month' => $calendar['month']->format('Y-m'),
            'label' => ucfirst($calendar['month']->translatedFormat('F Y')),
            'prev' => $calendar['prev'],
            'next' => $calendar['next'],
            'counts' => $calendar['counts'],
            'completion' => $calendar['completion'] === null ? null : round($calendar['completion'], 4),
            'projected_count' => $calendar['projectedCount'],
            'undated' => $calendar['undated'],
            'overdue_before' => $calendar['overdueBefore'],
            'days' => $calendar['days']->map(fn (array $day) => [
                'date' => $day['key'],
                'in_month' => $day['inMonth'],
                'is_today' => $day['isToday'],
                'is_past' => $day['isPast'],
                'counts' => $day['counts'],
                'tasks' => $day['tasks']->map(fn (Task $t) => Present::task($t))->values()->all(),
                'projected' => array_map(fn (array $p) => ['task_id' => $p['task']->id, 'title' => $p['task']->title, 'date' => $p['date']->toDateString()], $day['projected']),
            ])->values()->all(),
        ], ...self::lists()]);
    }

    public function show(Task $task)
    {
        return response()->json(['data' => Present::task($task->load('list', 'taskable'))]);
    }

    public function store(Request $request)
    {
        $task = Task::create(TaskData::validate($request->all()));

        return response()->json(['data' => Present::task($task->load('list', 'taskable')), 'message' => "Tarea «{$task->title}» agregada."], 201);
    }

    /** Solo cambia lo que se manda. */
    public function update(Request $request, Task $task)
    {
        $task->update(TaskData::validate($request->all() + TaskData::toInput($task)));

        return response()->json(['data' => Present::task($task->load('list', 'taskable')), 'message' => 'Tarea actualizada.']);
    }

    public function destroy(Task $task)
    {
        $task->delete();

        return response()->json(['message' => 'Tarea eliminada.']);
    }

    /**
     * La marca como hecha o la reabre. Una repetitiva crea la siguiente (`next`); una de
     * mantenimiento registra el servicio del bien.
     */
    public function toggle(Task $task)
    {
        if ($task->isDone()) {
            $task->reopen();

            return response()->json(['data' => Present::task($task->fresh(['list', 'taskable'])), 'next' => null, 'message' => "«{$task->title}» vuelve a estar pendiente."]);
        }

        $next = $task->complete();
        $message = "«{$task->title}» hecha.".($next ? ' La siguiente: '.$next->due_on->translatedFormat('D d \d\e F Y').'.' : '');

        return response()->json([
            'data' => Present::task($task->fresh(['list', 'taskable'])),
            'next' => $next ? Present::task($next->load('list', 'taskable')) : null,
            'message' => $message,
        ]);
    }

    /** @return \Closure(mixed): mixed el filtro de la lista elegida, como en la web */
    private function scope(Request $request): \Closure
    {
        $list = $request->filled('list') && $request->query('list') !== 'none' ? TaskList::findOrFail($request->integer('list')) : null;
        $general = $request->query('list') === 'none';
        $search = $request->string('q')->trim()->toString();

        return fn ($query) => $query->filter($list, $general, $search);
    }

    /** @return array{lists: mixed, general_count: int, all_count: int} */
    private static function lists(): array
    {
        return [
            'lists' => TaskList::withCount(['tasks' => fn ($q) => $q->pending()])->orderBy('name')->get()->map(fn (TaskList $l) => Present::taskList($l))->values(),
            'general_count' => Task::pending()->whereNull('task_list_id')->count(),
            'all_count' => Task::pending()->count(),
        ];
    }
}
