<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TaskCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_month_calendar_counts_done_pending_and_overdue_tasks(): void
    {
        Task::create(['title' => 'Pagar luz', 'due_on' => '2026-11-10', 'completed_at' => '2026-11-10 09:00:00']);
        Task::create(['title' => 'Cambiar filtro', 'due_on' => '2026-11-12']);           // vencida
        Task::create(['title' => 'Dentista', 'due_on' => '2026-11-25']);                 // pendiente
        Task::create(['title' => 'Sin fecha hecha', 'completed_at' => '2026-11-15 18:00:00']); // va en el día que se hizo
        Task::create(['title' => 'Regar', 'due_on' => '2026-11-20', 'repeat_every' => 3, 'repeat_unit' => 'day']);
        Task::create(['title' => 'Otro mes', 'due_on' => '2026-12-15']);
        Task::create(['title' => 'Algún día']);

        $response = $this->get(route('tasks.index'))->assertOk()->assertSee('Programadas')->assertSee('noviembre 2026');
        $calendar = $response->viewData('calendar');

        $this->assertSame(['total' => 5, 'done' => 2, 'pending' => 2, 'overdue' => 1], $calendar['counts']);
        // De lo que ya tocaba (pagar luz, filtro, la hecha sin fecha): 2 de 3
        $this->assertEqualsWithDelta(2 / 3, $calendar['completion'], 0.001);
        $this->assertSame(1, $calendar['undated']);

        $days = $calendar['days']->keyBy('key');
        $this->assertSame(['Sin fecha hecha'], $days['2026-11-15']['tasks']->pluck('title')->all());
        $this->assertSame(1, $days['2026-11-12']['counts']['overdue']);
        // Regar cada 3 días: 23, 26 y 29 se proyectan (y en la semana siguiente, 2 de diciembre)
        $this->assertSame('Regar', $days['2026-11-23']['projected'][0]['task']->title);
        $this->assertArrayHasKey('2026-12-02', $days->map(fn ($d) => $d['projected'])->filter()->all());
        $this->assertSame(3, $calendar['projectedCount']);

        $response->assertSee('Pagar luz')->assertSee('Cambiar filtro')->assertDontSee('Otro mes');

        // Otro mes y la lista elegida
        $this->get(route('tasks.index', ['month' => '2026-12']))->assertOk()->assertSee('Otro mes');
        $this->get(route('tasks.index', ['month' => 'no-es-mes']))->assertOk()->assertSee('noviembre 2026');
        $casa = TaskList::create(['name' => 'Casa']);
        Task::create(['title' => 'Pintar barda', 'due_on' => '2026-11-21', 'task_list_id' => $casa->id]);
        $this->assertSame(1, $this->get(route('tasks.index', ['list' => $casa->id]))->viewData('calendar')['counts']['total']);
    }
}
