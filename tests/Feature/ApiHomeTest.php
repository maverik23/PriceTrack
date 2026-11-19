<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\MaintenanceLog;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** API de la app móvil: Hogar (tareas y bienes). */
class ApiHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_tasks_crud_groups_and_toggle(): void
    {
        $listId = $this->postJson('/api/v1/task-lists', ['name' => 'Casa'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/task-lists', ['name' => 'Casa'])->assertStatus(422)->assertJsonValidationErrors('name');

        $id = $this->postJson('/api/v1/tasks', [
            'title' => 'Cambiar filtro', 'task_list_id' => $listId, 'due_on' => today()->subDay()->toDateString(),
            'priority' => 3, 'repeat_every' => 1, 'repeat_unit' => 'month',
        ])->assertCreated()->assertJsonPath('data.status', 'overdue')->assertJsonPath('data.repeats', 'Cada mes')->json('data.id');
        $this->postJson('/api/v1/tasks', ['title' => 'Pagar predial'])->assertCreated()->assertJsonPath('data.list', 'General');
        $this->postJson('/api/v1/tasks', ['title' => ''])->assertStatus(422)->assertJsonValidationErrors('title');

        // Solo cambia lo que se manda
        $this->putJson("/api/v1/tasks/{$id}", ['notes' => 'El de la cocina'])->assertOk()
            ->assertJsonPath('data.title', 'Cambiar filtro')->assertJsonPath('data.priority_value', 3)->assertJsonPath('data.list_id', $listId);

        $this->getJson('/api/v1/tasks')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('groups.0.key', 'overdue')->assertJsonPath('groups.0.total', 1)
            ->assertJsonPath('groups.3.total', 1)
            ->assertJsonPath('lists.0.pending', 1)
            ->assertJsonPath('all_count', 2);
        $this->getJson('/api/v1/tasks?list=none')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Pagar predial');
        $this->getJson('/api/v1/tasks?q=filtro')->assertOk()->assertJsonCount(1, 'data');

        // Completar una repetitiva crea la siguiente; reabrirla la quita
        $next = $this->postJson("/api/v1/tasks/{$id}/toggle")->assertOk()->assertJsonPath('data.status', 'done')
            ->assertJsonPath('next.due_on', today()->addMonthNoOverflow()->toDateString())->json('next.id');
        $this->getJson('/api/v1/tasks')->assertJsonPath('done.0.id', $id);
        $this->postJson("/api/v1/tasks/{$id}/toggle")->assertOk()->assertJsonPath('data.status', 'overdue')->assertJsonPath('next', null);
        $this->assertNull(Task::find($next));

        // Mover de fecha (la de la web) y una hecha no se mueve
        $this->patchJson("/api/v1/tasks/{$id}/move", ['due_on' => today()->addDays(3)->toDateString()])->assertOk()
            ->assertJsonPath('due_on', today()->addDays(3)->toDateString());
        $this->postJson("/api/v1/tasks/{$id}/toggle")->assertOk();
        $this->patchJson("/api/v1/tasks/{$id}/move", ['due_on' => today()->toDateString()])->assertStatus(422);

        $this->deleteJson("/api/v1/task-lists/{$listId}")->assertOk();
        $this->assertNull(Task::find($id)->task_list_id);
        $this->deleteJson("/api/v1/tasks/{$id}")->assertOk();
        $this->assertNull(Task::find($id));
    }

    public function test_task_calendar_of_the_month(): void
    {
        $day = today()->startOfMonth()->addDays(9);
        Task::create(['title' => 'Regar', 'due_on' => $day, 'repeat_every' => 1, 'repeat_unit' => 'week', 'priority' => 2]);
        Task::create(['title' => 'Sin fecha', 'priority' => 2]);

        $data = $this->getJson('/api/v1/tasks/calendar?month='.$day->format('Y-m'))->assertOk()->json('data');
        $this->assertSame($day->format('Y-m'), $data['month']);
        $this->assertSame(1, $data['counts']['total']);
        $this->assertSame(1, $data['undated']);
        $cell = collect($data['days'])->firstWhere('date', $day->toDateString());
        $this->assertSame('Regar', $cell['tasks'][0]['title']);
        // La semana siguiente está proyectada
        $this->assertSame('Regar', collect($data['days'])->firstWhere('date', $day->copy()->addWeek()->toDateString())['projected'][0]['title']);
        $this->assertSame(0, count($data['days']) % 7);
    }

    public function test_assets_records_and_plans(): void
    {
        $id = $this->postJson('/api/v1/assets', [
            'name' => 'Auto', 'category' => 'Vehículos', 'purchased_on' => today()->subYears(2)->toDateString(), 'purchase_price' => 300000,
            'valuation_method' => 'annual_rate', 'annual_rate' => -10, 'meter_unit' => 'km',
        ])->assertCreated()->assertJsonPath('data.status', 'active')->json('data.id');
        $this->postJson('/api/v1/assets', ['name' => 'X', 'valuation_method' => 'straight_line'])->assertStatus(422)->assertJsonValidationErrors('useful_life_years');

        // Solo cambia lo que se manda
        $this->putJson("/api/v1/assets/{$id}", ['location' => 'Cochera'])->assertOk()
            ->assertJsonPath('data.location', 'Cochera')->assertJsonPath('data.annual_rate', -10);

        $this->postJson("/api/v1/assets/{$id}/valuations", ['valued_on' => today()->toDateString(), 'value' => 250000])->assertOk()
            ->assertJsonPath('data.current_value', 250000)->assertJsonCount(1, 'data.valuations');
        // Una por día: corrige la de ese día
        $this->postJson("/api/v1/assets/{$id}/valuations", ['valued_on' => today()->toDateString(), 'value' => 245000])->assertOk()
            ->assertJsonCount(1, 'data.valuations')->assertJsonPath('data.current_value', 245000);
        $reading = $this->postJson("/api/v1/assets/{$id}/readings", ['read_on' => today()->toDateString(), 'reading' => 41000])->assertOk()
            ->assertJsonPath('data.current_meter', 41000)->json('data.readings.0.id');

        $plan = $this->postJson("/api/v1/assets/{$id}/plans", ['name' => 'Afinación', 'every' => 6, 'every_unit' => 'month', 'every_meter' => 10000])
            ->assertCreated()->assertJsonPath('data.plans.0.name', 'Afinación')->json('data.plans.0');
        $this->assertNotNull($plan['task_id']);
        $this->assertSame('Mantenimiento', Task::find($plan['task_id'])->list->name);
        $this->putJson("/api/v1/assets/{$id}/plans/{$plan['id']}", ['estimated_cost' => 2500])->assertOk()
            ->assertJsonPath('data.plans.0.estimated_cost', 2500)->assertJsonPath('data.plans.0.every_meter', 10000);

        $log = $this->postJson("/api/v1/assets/{$id}/logs", ['done_on' => today()->toDateString(), 'maintenance_plan_id' => $plan['id'], 'cost' => 2400, 'meter_reading' => 41000])
            ->assertOk()->assertJsonPath('data.services.0.plan', 'Afinación')
            ->assertJsonPath('data.plans.0.last_done_on', today()->toDateString())
            ->assertJsonPath('data.plans.0.next_meter', 51000)->json('data.services.0.id');

        $detail = $this->getJson("/api/v1/assets/{$id}")->assertOk()->json('data');
        $this->assertNotEmpty($detail['series']);
        $this->assertSame([$plan['task_id']], array_column($detail['tasks'], 'id'));
        $this->assertSame('Cochera', $detail['input']['location']);

        $this->getJson('/api/v1/assets')->assertOk()->assertJsonPath('data.0.name', 'Auto')->assertJsonPath('summary.value', 245000);
        $this->getJson('/api/v1/assets?detail=1')->assertOk()->assertJsonStructure(['data' => [['valuations', 'services', 'series']]]);
        $this->getJson('/api/v1/assets/options')->assertOk()->assertJsonPath('categories', ['Vehículos'])->assertJsonPath('units.month.one', 'mes');
        $this->getJson('/api/v1/reports/assets')->assertOk()
            ->assertJsonPath('data.rows.0.total', 2400)
            ->assertJsonPath('data.totals.'.today()->year, 2400)
            ->assertJsonPath('data.plans.0.name', 'Afinación');

        // Bajas (con scopeBindings: un registro de otro bien da 404)
        $other = Asset::create(['name' => 'Bici']);
        $this->deleteJson("/api/v1/assets/{$other->id}/logs/{$log}")->assertNotFound();
        $this->deleteJson("/api/v1/assets/{$id}/logs/{$log}")->assertOk()->assertJsonCount(0, 'data.services');
        $this->deleteJson("/api/v1/assets/{$id}/readings/{$reading}")->assertOk()->assertJsonCount(0, 'data.readings');
        $this->deleteJson("/api/v1/assets/{$id}/plans/{$plan['id']}")->assertOk()->assertJsonCount(0, 'data.plans');
        $this->assertSame(0, MaintenanceLog::count());
        $this->deleteJson("/api/v1/assets/{$id}")->assertOk();
        $this->assertNull(Asset::find($id));
    }

    public function test_asset_documents(): void
    {
        Storage::fake('local');
        $asset = Asset::create(['name' => 'Refrigerador']);

        $data = $this->post("/api/v1/assets/{$asset->id}/attachments", [
            'files' => [UploadedFile::fake()->image('frente.jpg'), UploadedFile::fake()->create('garantia.pdf', 20, 'application/pdf')],
        ])->assertOk()->json('data');
        $this->assertCount(2, $data['attachments']);
        $this->assertSame('attachments/'.$data['attachments'][0]['id'], $data['photo']['url']);
        $this->get('/api/v1/'.$data['attachments'][1]['url'])->assertOk();
    }
}
