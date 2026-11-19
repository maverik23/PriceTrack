<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskList;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TasksTest extends TestCase
{
    use RefreshDatabase;

    public function test_tasks_are_grouped_by_due_date(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 10:00'));
        $casa = TaskList::create(['name' => 'Casa']);

        $this->post(route('tasks.store'), ['title' => 'Pagar luz', 'due_on' => '2026-11-15', 'task_list_id' => $casa->id])->assertSessionHasNoErrors();
        $this->post(route('tasks.store'), ['title' => 'Regar plantas', 'due_on' => '2026-11-19'])->assertSessionHasNoErrors();
        $this->post(route('tasks.store'), ['title' => 'Llevar el auto', 'due_on' => '2026-11-25', 'priority' => 3])->assertSessionHasNoErrors();
        $this->post(route('tasks.store'), ['title' => 'Pintar la reja'])->assertSessionHasNoErrors();

        $this->get(route('tasks.index', ['view' => 'list']))->assertOk()
            ->assertSeeInOrder(['Vencidas', 'Pagar luz', 'hace 4 días', 'Hoy', 'Regar plantas', 'Próximas', 'Llevar el auto', 'Alta', 'Sin fecha', 'Pintar la reja']);

        $this->get(route('tasks.index', ['list' => $casa->id, 'view' => 'list']))->assertOk()->assertSee('Pagar luz')->assertDontSee('Regar plantas');
        $this->get(route('tasks.index', ['list' => 'none', 'view' => 'list']))->assertOk()->assertSee('Regar plantas')->assertDontSee('Pagar luz');
        $this->assertSame(2, Task::dueBy(today())->count());
    }

    public function test_completing_a_recurring_task_creates_the_next_from_the_completion_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 10:00'));
        $this->post(route('tasks.store'), [
            'title' => 'Cambiar filtro', 'due_on' => '2026-11-10', 'repeat_every' => 3, 'repeat_unit' => 'month', 'priority' => 3,
        ])->assertSessionHasNoErrors();
        $task = Task::firstOrFail();
        $this->assertSame('Cada 3 meses', $task->repeatLabel());

        $this->post(route('tasks.toggle', $task))->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, '19 de febrero 2027'));

        $this->assertNotNull($task->fresh()->completed_at);
        $next = Task::pending()->firstOrFail();
        $this->assertSame('2027-02-19', $next->due_on->toDateString());
        $this->assertSame($task->id, $next->repeated_from_id);
        $this->assertSame(3, $next->priority);
        $this->assertSame('month', $next->repeat_unit);

        // Deshacer quita la siguiente que no se ha hecho
        $this->post(route('tasks.toggle', $task->fresh()));
        $this->assertNull($task->fresh()->completed_at);
        $this->assertSame(1, Task::count());

        $this->get(route('tasks.index', ['view' => 'list']))->assertOk()->assertSee('Cada 3 meses');
    }

    public function test_next_dates_do_not_overflow_and_plain_tasks_do_not_repeat(): void
    {
        $task = new Task(['title' => 'X', 'repeat_every' => 1, 'repeat_unit' => 'month']);
        $this->assertSame('2027-02-28', $task->nextDueOn(Carbon::parse('2027-01-31'))->toDateString());
        $task->repeat_unit = 'year';
        $this->assertSame('2029-02-28', $task->nextDueOn(Carbon::parse('2028-02-29'))->toDateString());
        $task->fill(['repeat_every' => 2, 'repeat_unit' => 'week']);
        $this->assertSame('2026-12-03', $task->nextDueOn(Carbon::parse('2026-11-19 23:30'))->toDateString());

        $plain = Task::create(['title' => 'Una vez']);
        $this->assertNull($plain->complete());
        $this->assertSame(1, Task::count());
        $this->get(route('tasks.index', ['view' => 'list']))->assertOk()->assertSee('Hechas en los últimos 7 días')->assertSee('Una vez');
    }

    public function test_validation_and_editing(): void
    {
        $task = Task::create(['title' => 'Barrer', 'repeat_every' => 1, 'repeat_unit' => 'week']);
        $this->get(route('tasks.edit', $task))->assertOk()->assertSee('Barrer');
        $this->put(route('tasks.update', $task), ['title' => 'Barrer el patio', 'notes' => 'Con la escoba nueva', 'redirect' => '//evil.com'])
            ->assertRedirect(route('tasks.index'));
        $task->refresh();
        $this->assertSame('Barrer el patio', $task->title);
        $this->assertNull($task->repeat_unit, 'sin repetición en el formulario deja de repetirse');

        $this->delete(route('tasks.destroy', $task))->assertRedirect(route('tasks.index'));
        $this->assertSame(0, Task::count());

        $this->post(route('tasks.store'), ['title' => ''])->assertSessionHasErrors('title');
        $this->post(route('tasks.store'), ['title' => 'X', 'repeat_every' => 2])->assertSessionHasErrors('repeat_unit');
        $this->post(route('tasks.store'), ['title' => 'X', 'repeat_unit' => 'fortnight', 'repeat_every' => 1])->assertSessionHasErrors('repeat_unit');
        $this->post(route('tasks.store'), ['title' => 'X', 'priority' => 9])->assertSessionHasErrors('priority');
        $this->assertSame(0, Task::count());
    }

    public function test_task_lists(): void
    {
        $this->post(route('task-lists.store'), ['name' => 'Auto'])->assertSessionHasNoErrors();
        $this->post(route('task-lists.store'), ['name' => 'Auto'])->assertSessionHasErrors('name');
        $list = TaskList::firstOrFail();
        $task = Task::create(['title' => 'Verificación', 'task_list_id' => $list->id]);

        $this->put(route('task-lists.update', $list), ['name' => 'Coche'])->assertSessionHasNoErrors();
        $this->assertSame('Coche', $list->fresh()->name);
        $this->get(route('tasks.index', ['list' => $list->id, 'view' => 'list']))->assertOk()->assertSee('Coche')->assertSee('Verificación');

        $this->delete(route('task-lists.destroy', $list))->assertRedirect(route('tasks.index'));
        $this->assertNull($task->fresh()->task_list_id);
        $this->get(route('tasks.index', ['list' => 999]))->assertOk();
    }

    public function test_a_pending_task_is_moved_to_another_day_by_dragging_it(): void
    {
        Carbon::setTestNow('2026-11-19 10:00');
        $task = Task::create(['title' => 'Pagar el agua', 'due_on' => '2026-11-10', 'priority' => 2]);

        $this->patchJson(route('tasks.move', $task), ['due_on' => '2026-11-24'])->assertOk()
            ->assertJson(['ok' => true, 'due_on' => '2026-11-24', 'from' => '2026-11-10']);
        $this->assertSame('2026-11-24', $task->fresh()->due_on->toDateString());

        $this->patchJson(route('tasks.move', $task), ['due_on' => 'mañana'])->assertUnprocessable();
        $this->patch(route('tasks.move', $task), ['due_on' => '2026-11-25'])->assertRedirect()->assertSessionHas('success');

        $task->fresh()->complete();
        $this->patchJson(route('tasks.move', $task), ['due_on' => '2026-11-30'])->assertUnprocessable();
        $this->assertSame('2026-11-25', $task->fresh()->due_on->toDateString(), 'una hecha no se mueve');
    }

    public function test_the_calendar_shows_overdue_tasks_in_a_side_panel_ready_to_drag(): void
    {
        Carbon::setTestNow('2026-11-19 10:00');
        $late = Task::create(['title' => 'Revisar la caldera', 'due_on' => '2026-11-02', 'priority' => 2]);
        Task::create(['title' => 'Comprar focos', 'due_on' => '2026-11-25', 'priority' => 2]);

        $this->get(route('tasks.index'))->assertOk()
            ->assertSee('overdue-title', false)->assertSee('Revisar la caldera')->assertSee('hace 17 días')
            ->assertSee('data-drop-day="2026-11-24"', false)
            ->assertSee(e($late->dragPayload()), false);

        // ?day= deja elegido ese día después de mover
        $this->get(route('tasks.index', ['day' => '2026-11-25']))->assertSee("selected: '2026-11-25'", false);

        // En la vista de lista ya salen arriba: sin panel
        $this->get(route('tasks.index', ['view' => 'list']))->assertDontSee('overdue-title', false);
    }
}
