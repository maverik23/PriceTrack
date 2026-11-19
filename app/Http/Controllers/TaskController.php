<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskList;
use App\Support\Paginate;
use App\Support\TaskCalendar;
use App\Support\TaskData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /** Cuántas vencidas muestra el panel lateral. */
    public const OVERDUE_WIDGET = 8;

    /**
     * Calendario del mes (por defecto; ?month=AAAA-MM) o lista (?view=list): pendientes por grupo
     * (vencidas, hoy, próximas, sin fecha) y lo completado en la última semana.
     */
    public function index(Request $request)
    {
        $list = $request->filled('list') && $request->query('list') !== 'none'
            ? TaskList::find($request->integer('list'))
            : null;
        $general = $request->query('list') === 'none';

        $search = $request->string('q')->trim()->toString();
        $scope = fn ($query) => $query->filter($list, $general, $search);

        $view = $request->query('view') === 'list' ? 'list' : 'calendar';
        $month = rescue(fn () => preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? CarbonImmutable::createFromFormat('!Y-m', $request->query('month'))
            : null, null, false) ?? CarbonImmutable::today();

        // En la lista se pagina en el orden de los grupos (vencidas, hoy, próximas, sin fecha)
        $pending = Task::with('list', 'taskable')->pending()->tap($scope)
            ->orderByRaw('due_on is null')->orderBy('due_on')->orderByDesc('priority')->orderBy('id')
            ->paginate(Paginate::perPage($request))
            ->withQueryString();
        $today = today();
        $totals = Task::pending()->tap($scope)->toBase()
            ->selectRaw("case when due_on is null then 'undated' when due_on < ? then 'overdue' when due_on < ? then 'today' else 'upcoming' end as status_group, count(*) as total",
                [$today->toDateString(), $today->copy()->addDay()->toDateString()])
            ->groupBy('status_group')
            ->pluck('total', 'status_group');
        $tasks = $pending->getCollection();

        return view('tasks.index', [
            'view' => $view,
            'calendar' => $view === 'calendar' ? TaskCalendar::month($month, $scope) : null,
            'list' => $list,
            'general' => $general,
            'pending' => $pending,
            // Panel lateral: las vencidas más viejas primero (se arrastran al calendario para moverlas)
            'overdue' => Task::with('list', 'taskable')->pending()->tap($scope)
                ->whereNotNull('due_on')->where('due_on', '<', $today->toDateString())
                ->orderBy('due_on')->orderByDesc('priority')->limit(self::OVERDUE_WIDGET)->get(),
            'overdueTotal' => (int) ($totals['overdue'] ?? 0),
            'search' => $search,
            'groups' => collect([
                'overdue' => 'Vencidas', 'today' => 'Hoy', 'upcoming' => 'Próximas', 'undated' => 'Sin fecha',
            ])->map(fn (string $title, string $key) => [
                $title,
                $tasks->filter(fn (Task $t) => $t->status($today) === $key)->values(),
                (int) ($totals[$key] ?? 0),
            ])->all(),
            'done' => Task::with('list')->completed()->tap($scope)
                ->where('completed_at', '>=', now()->subDays(7))
                ->orderByDesc('completed_at')->get(),
            'lists' => TaskList::withCount(['tasks' => fn ($q) => $q->pending()])->orderBy('name')->get(),
            'generalCount' => Task::pending()->whereNull('task_list_id')->count(),
            'allCount' => Task::pending()->count(),
        ]);
    }

    public function store(Request $request)
    {
        $task = Task::create($this->validated($request));

        return back()->with('success', "Tarea «{$task->title}» agregada.");
    }

    public function edit(Task $task)
    {
        return view('tasks.edit', ['task' => $task, 'lists' => TaskList::orderBy('name')->get()]);
    }

    public function update(Request $request, Task $task)
    {
        $task->update($this->validated($request));

        return redirect($this->safeRedirect($request) ?? route('tasks.index'))->with('success', 'Tarea actualizada.');
    }

    public function destroy(Request $request, Task $task)
    {
        $task->delete();

        return redirect($this->safeRedirect($request) ?? route('tasks.index'))->with('success', 'Tarea eliminada.');
    }

    /** Marca o desmarca como hecha (con un clic). Las repetitivas crean la siguiente. */
    /**
     * Cambia la fecha de una tarea pendiente (arrastrar y soltar en el calendario).
     * Responde JSON al navegador; sin JSON, regresa a la pantalla.
     */
    public function move(Request $request, Task $task)
    {
        abort_if($task->isDone(), 422, 'Una tarea hecha no se mueve: reábrela primero.');
        $data = $request->validate(['due_on' => ['required', 'date']], [], ['due_on' => 'fecha']);

        $from = $task->due_on?->toDateString();
        $task->update(['due_on' => $data['due_on']]);
        $label = ucfirst($task->due_on->translatedFormat('D d \d\e F'));

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $task->id, 'due_on' => $task->due_on->toDateString(), 'from' => $from, 'label' => $label]);
        }

        return back()->with('success', "«{$task->title}» pasó al {$label}.");
    }

    public function toggle(Task $task)
    {
        if ($task->isDone()) {
            $task->reopen();

            return back()->with('success', "«{$task->title}» vuelve a estar pendiente.");
        }

        $next = $task->complete();
        $message = "«{$task->title}» hecha.";
        if ($next) {
            $message .= ' La siguiente: '.$next->due_on->translatedFormat('D d \d\e F Y').'.';
        }

        return back()->with('success', $message);
    }

    private function safeRedirect(Request $request): ?string
    {
        $to = (string) $request->input('redirect');

        return str_starts_with($to, '/') && ! str_starts_with($to, '//') ? $to : null;
    }

    private function validated(Request $request): array
    {
        return TaskData::validate($request->all());
    }
}
