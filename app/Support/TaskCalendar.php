<?php

namespace App\Support;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;

/**
 * Calendario de tareas de un mes (semanas de lunes a domingo).
 *
 * - Cada tarea va en su fecha (`due_on`); una hecha sin fecha, en el día en que se hizo.
 * - Estado por día: hechas, pendientes y vencidas (pendientes con fecha pasada).
 * - Repetitivas: sus siguientes fechas se proyectan desde la fecha pendiente (como si se
 *   hicieran a tiempo) para planear; no son tareas todavía y no entran en los conteos.
 * - Conteos del mes: programadas, hechas, pendientes, vencidas y el % de cumplimiento
 *   (hechas ÷ las que ya tocaban).
 */
class TaskCalendar
{
    /** Repeticiones proyectadas como máximo por tarea (una diaria llena el mes). */
    private const MAX_PROJECTED = 42;

    /**
     * @param  Closure  $scope  filtro de la lista elegida (se aplica a las consultas)
     * @return array<string, mixed>
     */
    public static function month(CarbonImmutable $month, Closure $scope, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? today())->toDateString());
        $month = $month->startOfMonth();
        $start = $month->startOfWeek(CarbonInterface::MONDAY);
        $end = $month->endOfMonth()->endOfWeek(CarbonInterface::SUNDAY)->startOfDay();
        $after = $end->addDay()->toDateString();

        // Rango semiabierto: la fecha se guarda con hora en algunas bases
        $tasks = Task::with('list', 'taskable')->tap($scope)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('due_on', '>=', $start->toDateString())->where('due_on', '<', $after))
                ->orWhere(fn ($q) => $q->whereNull('due_on')->where('completed_at', '>=', $start->toDateString())->where('completed_at', '<', $after)))
            ->orderByDesc('priority')->orderBy('id')
            ->get();
        $byDay = $tasks->groupBy(fn (Task $t) => ($t->due_on ?? $t->completed_at)->toDateString());

        $projected = self::projections(Task::with('list')->tap($scope)->pending()->whereNotNull('due_on')
            ->whereNotNull('repeat_every')->where('due_on', '<', $after)->get(), $start, $end);

        $days = collect();
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $key = $day->toDateString();
            $ofDay = $byDay->get($key, collect());
            $days->push([
                'date' => $day,
                'key' => $key,
                'inMonth' => $day->month === $month->month,
                'isToday' => $day->isSameDay($today),
                'isPast' => $day->lt($today),
                'tasks' => $ofDay,
                'projected' => $projected[$key] ?? [],
                'counts' => self::counts($ofDay, $today),
            ]);
        }

        $inMonth = $tasks->filter(fn (Task $t) => ($t->due_on ?? $t->completed_at)->month === $month->month
            && ($t->due_on ?? $t->completed_at)->year === $month->year);
        $counts = self::counts($inMonth, $today);
        // Cumplimiento: de lo que ya tocaba (hasta hoy), cuánto se hizo
        $due = $inMonth->filter(fn (Task $t) => $t->isDone() || ($t->due_on && $t->due_on->lte($today)));

        return [
            'month' => $month,
            'prev' => $month->subMonth()->format('Y-m'),
            'next' => $month->addMonth()->format('Y-m'),
            'weeks' => $days->chunk(7),
            'days' => $days,
            'counts' => $counts,
            'completion' => $due->isEmpty() ? null : $due->filter->isDone()->count() / $due->count(),
            'projectedCount' => collect($projected)->flatten(1)->filter(fn ($p) => $p['date']->month === $month->month)->count(),
            'undated' => Task::tap($scope)->pending()->whereNull('due_on')->count(),
            'overdueBefore' => Task::tap($scope)->pending()->whereNotNull('due_on')->where('due_on', '<', $start->toDateString())->count(),
        ];
    }

    /** @return array{total: int, done: int, pending: int, overdue: int} */
    public static function counts(Collection $tasks, CarbonImmutable $today): array
    {
        $done = $tasks->filter->isDone()->count();
        $overdue = $tasks->filter(fn (Task $t) => $t->status($today) === 'overdue')->count();

        return ['total' => $tasks->count(), 'done' => $done, 'pending' => $tasks->count() - $done - $overdue, 'overdue' => $overdue];
    }

    /**
     * Siguientes fechas de las repetitivas pendientes dentro del rango (sin la pendiente misma).
     *
     * @return array<string, list<array{task: Task, date: CarbonImmutable}>>
     */
    private static function projections(Collection $recurring, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $out = [];
        foreach ($recurring as $task) {
            $date = CarbonImmutable::parse($task->due_on->toDateString());
            $shown = 0;
            // Una diaria vencida hace mucho deja de proyectarse antes de llegar al mes: está vencida, que se vea así
            for ($i = 0; $i < 1000 && $shown < self::MAX_PROJECTED; $i++) {
                $date = $task->nextDueOn($date);
                if (! $date || $date->gt($end)) {
                    break;
                }
                if ($date->gte($start)) {
                    $out[$date->toDateString()][] = ['task' => $task, 'date' => $date];
                    $shown++;
                }
            }
        }

        return $out;
    }
}
