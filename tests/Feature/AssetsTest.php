<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\MaintenanceLog;
use App\Models\MaintenancePlan;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-19 10:00'));
    }

    private function asset(array $data = []): Asset
    {
        return Asset::create([
            'name' => 'Auto', 'purchased_on' => '2024-11-19', 'purchase_price' => 300000, ...$data,
        ]);
    }

    public function test_value_without_estimation_is_the_last_valuation(): void
    {
        $asset = $this->asset();
        $this->assertEqualsWithDelta(300000, $asset->currentValue(), 0.01);
        $this->assertNull($asset->valueOn(Carbon::parse('2024-01-01')), 'antes de comprarlo no vale nada');

        $asset->valuations()->create(['valued_on' => '2025-06-01', 'value' => 250000]);
        $asset->valuations()->create(['valued_on' => '2026-06-01', 'value' => 220000]);
        $asset->load('valuations');
        $this->assertEqualsWithDelta(250000, $asset->valueOn(Carbon::parse('2026-01-01')), 0.01);
        $this->assertEqualsWithDelta(220000, $asset->currentValue(), 0.01);
        $this->assertEqualsWithDelta(-80000, $asset->gain(), 0.01);
    }

    public function test_straight_line_depreciation_stops_at_salvage_value(): void
    {
        // (300,000 − 30,000) ÷ 10 años = 27,000 al año
        $asset = $this->asset(['valuation_method' => 'straight_line', 'useful_life_years' => 10, 'salvage_value' => 30000]);
        $this->assertEqualsWithDelta(300000 - 27000 * (730 / 365.25), $asset->currentValue(), 1);
        $this->assertEqualsWithDelta(30000, $asset->valueOn(Carbon::parse('2040-01-01')), 0.01);

        // Desde una captura sigue bajando al mismo ritmo
        $asset->valuations()->create(['valued_on' => '2026-11-19', 'value' => 200000]);
        $asset->load('valuations');
        $this->assertEqualsWithDelta(200000 - 27000, $asset->valueOn(Carbon::parse('2027-11-19')), 30);
    }

    public function test_annual_rate_for_appreciation(): void
    {
        $asset = $this->asset(['name' => 'Casa', 'purchase_price' => 2000000, 'valuation_method' => 'annual_rate', 'annual_rate' => 10]);
        // Dos años (730 días) al 10 %
        $this->assertEqualsWithDelta(2000000 * 1.1 ** (730 / 365.25), $asset->currentValue(), 1);
        $this->assertGreaterThan(0, $asset->gain());
        $this->assertNotEmpty($asset->valueSeries());
    }

    public function test_sold_assets_are_worth_zero_after_disposal_and_gain_uses_the_amount_received(): void
    {
        $asset = $this->asset(['status' => 'sold', 'disposed_on' => '2026-06-01', 'disposal_amount' => 240000]);
        $asset->maintenanceLogs()->create(['done_on' => '2025-05-01', 'cost' => 5000]);
        $asset->load('maintenanceLogs');

        $this->assertSame(0.0, $asset->currentValue());
        $this->assertEqualsWithDelta(300000, $asset->valueOn(Carbon::parse('2026-05-31')), 0.01);
        $this->assertEqualsWithDelta(-60000, $asset->gain(), 0.01);
        // 300,000 + 5,000 − 240,000
        $this->assertEqualsWithDelta(65000, $asset->ownershipCost(), 0.01);
    }

    public function test_a_plan_keeps_a_reminder_task_that_moves_with_each_service(): void
    {
        $asset = $this->asset(['purchased_on' => '2026-01-10']);
        $this->post(route('assets.plans.store', $asset), ['name' => 'Afinación', 'every' => 6, 'every_unit' => 'month', 'estimated_cost' => 2500])
            ->assertSessionHasNoErrors();
        $plan = MaintenancePlan::firstOrFail();

        $task = $plan->task;
        $this->assertSame('Afinación · Auto', $task->title);
        $this->assertSame('2026-07-10', $task->due_on->toDateString());
        $this->assertSame('Mantenimiento', $task->list->name);
        $this->assertTrue($task->taskable->is($asset));
        $this->assertSame('overdue', $plan->status());

        // Registrar el servicio mueve el recordatorio
        $this->post(route('assets.logs.store', $asset), ['maintenance_plan_id' => $plan->id, 'done_on' => '2026-11-01', 'cost' => 2300])
            ->assertSessionHasNoErrors();
        $this->assertSame('2027-05-01', $task->fresh()->due_on->toDateString());
        $this->assertSame('ok', $plan->fresh()->status());

        // Completar la tarea registra el servicio y crea el siguiente recordatorio
        $this->post(route('tasks.toggle', $task))->assertRedirect();
        $plan->refresh();
        $this->assertSame(2, $plan->logs()->count());
        $this->assertNotSame($task->id, $plan->task_id);
        $this->assertSame('2027-05-19', $plan->task->due_on->toDateString());
        $this->assertSame(1, Task::pending()->count());

        // Reabrirla lo deshace
        $this->post(route('tasks.toggle', $task->fresh()));
        $plan->refresh();
        $this->assertSame(1, $plan->logs()->count());
        $this->assertSame($task->id, $plan->task_id);
        $this->assertSame('2027-05-01', $task->fresh()->due_on->toDateString());
        $this->assertSame(1, Task::count());

        $this->get(route('assets.show', $asset))->assertOk()->assertSee('Afinación')->assertSee('Al día');
        $this->get(route('tasks.index'))->assertOk()->assertSee('Afinación · Auto');

        $this->delete(route('assets.plans.destroy', [$asset, $plan]))->assertRedirect();
        $this->assertSame(0, Task::count());
        $this->assertSame(1, MaintenanceLog::count(), 'los servicios hechos se conservan');
    }

    public function test_meter_based_plans(): void
    {
        $asset = $this->asset(['meter_unit' => 'km']);
        $asset->meterReadings()->create(['read_on' => '2024-11-19', 'reading' => 20000]);
        $this->post(route('assets.plans.store', $asset), ['name' => 'Aceite', 'every_meter' => 10000])->assertSessionHasNoErrors();
        $plan = MaintenancePlan::firstOrFail();

        $this->assertSame(30000.0, $plan->nextMeter());
        $this->assertNull($plan->task->due_on);
        $this->assertStringContainsString('30,000 km', $plan->task->notes);

        $this->post(route('assets.readings.store', $asset), ['read_on' => '2026-11-01', 'reading' => 29500])->assertSessionHasNoErrors();
        $this->assertSame('soon', $plan->fresh()->status());
        $this->post(route('assets.readings.store', $asset), ['read_on' => '2026-11-18', 'reading' => 30100]);
        $this->assertSame('overdue', $plan->fresh()->status());

        $this->post(route('assets.logs.store', $asset), ['maintenance_plan_id' => $plan->id, 'done_on' => '2026-11-18', 'meter_reading' => 30100]);
        $this->assertSame(40100.0, $plan->fresh()->nextMeter());
        $this->assertSame('ok', $plan->fresh()->status());
    }

    public function test_selling_or_deleting_an_asset_removes_its_pending_reminders(): void
    {
        $asset = $this->asset();
        $this->post(route('assets.plans.store', $asset), ['name' => 'Lavado', 'every' => 1, 'every_unit' => 'month']);
        $this->assertSame(1, Task::pending()->count());

        $this->put(route('assets.update', $asset), [
            'name' => 'Auto', 'status' => 'sold', 'disposed_on' => '2026-11-01', 'disposal_amount' => 200000, 'valuation_method' => 'none',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, Task::count());

        $this->put(route('assets.update', $asset), ['name' => 'Auto', 'status' => 'active', 'valuation_method' => 'none'])->assertSessionHasNoErrors();
        $this->assertSame(1, Task::pending()->count());
        $this->assertNull($asset->fresh()->disposed_on);

        $this->delete(route('assets.destroy', $asset))->assertRedirect(route('assets.index'));
        $this->assertSame(0, Task::count());
    }

    public function test_crud_validation_and_screens(): void
    {
        $this->get(route('assets.create'))->assertOk();
        $this->post(route('assets.store'), ['name' => 'Refri', 'status' => 'active', 'valuation_method' => 'straight_line'])
            ->assertSessionHasErrors('useful_life_years');
        $this->post(route('assets.store'), ['name' => 'Refri', 'status' => 'sold', 'valuation_method' => 'none'])
            ->assertSessionHasErrors('disposed_on');
        $this->post(route('assets.store'), ['name' => 'Refri', 'status' => 'active', 'valuation_method' => 'annual_rate'])
            ->assertSessionHasErrors('annual_rate');

        $this->post(route('assets.store'), [
            'name' => 'Refri', 'category' => 'Electrodomésticos', 'location' => 'Cocina', 'status' => 'active',
            'purchased_on' => '2025-01-15', 'purchase_price' => 18000, 'warranty_until' => '2026-12-15',
            'valuation_method' => 'straight_line', 'useful_life_years' => 10, 'annual_rate' => 5,
        ])->assertSessionHasNoErrors();
        $refri = Asset::firstOrFail();
        $this->assertNull($refri->annual_rate, 'solo se guardan los campos del método elegido');
        $this->post(route('assets.store'), ['name' => 'Lavadora', 'category' => 'electrodomesticos', 'status' => 'active', 'valuation_method' => 'none']);
        $this->assertSame('Electrodomésticos', Asset::where('name', 'Lavadora')->value('category'));

        $this->post(route('assets.valuations.store', $refri), ['valued_on' => '2026-11-01', 'value' => 15000])->assertSessionHasNoErrors();
        $this->post(route('assets.valuations.store', $refri), ['valued_on' => '2026-11-01', 'value' => 14000]);
        $this->assertSame(1, $refri->valuations()->count(), 'una captura por día');

        $this->get(route('assets.index'))->assertOk()->assertSee('Refri')->assertSee('Garantías por vencer');
        $this->get(route('assets.index', ['status' => 'all']))->assertOk()->assertSee('Lavadora');
        $this->get(route('assets.show', $refri))->assertOk()->assertSee('Valor en el tiempo');
        $this->get(route('assets.edit', $refri))->assertOk();
        $this->get(route('reports.assets'))->assertOk()->assertSee('Refri')->assertSee('15 dic');

        // Las capturas de otro bien no se pueden borrar desde este
        $other = Asset::where('name', 'Lavadora')->first();
        $this->delete(route('assets.valuations.destroy', [$other, $refri->valuations()->first()]))->assertNotFound();
    }
}
