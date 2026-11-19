<?php

namespace Tests\Feature;

use App\Mcp\PriceTrackServer;
use App\Mcp\Tools\CorrectFinanceRecord;
use App\Mcp\Tools\DeleteAssetRecord;
use App\Mcp\Tools\DeleteFinanceRecord;
use App\Mcp\Tools\DeleteTaskList;
use App\Mcp\Tools\GetAsset;
use App\Mcp\Tools\GetFinanceRecords;
use App\Mcp\Tools\RecordAsset;
use App\Mcp\Tools\RecordIncome;
use App\Mcp\Tools\RecordInvestment;
use App\Mcp\Tools\SaveAsset;
use App\Mcp\Tools\SaveDebt;
use App\Mcp\Tools\SaveInvestmentAccount;
use App\Mcp\Tools\SaveMaintenancePlan;
use App\Mcp\Tools\SaveTaskList;
use App\Models\Asset;
use App\Models\Debt;
use App\Models\Income;
use App\Models\IncomeSource;
use App\Models\InvestmentAccount;
use App\Models\InvestmentTransaction;
use App\Models\MaintenancePlan;
use App\Models\Task;
use App\Models\TaskList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class McpHomeAndRecordsTest extends TestCase
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

    public function test_assets_are_created_valued_serviced_planned_and_deleted(): void
    {
        PriceTrackServer::tool(SaveAsset::class, [
            'name' => 'Auto', 'category' => 'Vehículos', 'brand' => 'Mazda', 'model' => '3', 'serial_number' => 'VIN123', 'location' => 'Cochera',
            'purchased_on' => '2022-01-15', 'purchase_price' => 400000, 'warranty_until' => '2025-01-15', 'notes' => 'De agencia',
            'valuation_method' => 'annual_rate', 'annual_rate' => -10, 'meter_unit' => 'km',
        ])->assertOk();
        $car = Asset::firstOrFail();
        $this->assertSame(['Mazda', 'VIN123', 'annual_rate', 'km'], [$car->brand, $car->serial_number, $car->valuation_method, $car->meter_unit]);

        // Cambiar solo algo conserva lo demás; las reglas son las del formulario
        PriceTrackServer::tool(SaveAsset::class, ['id' => $car->id, 'location' => 'Calle'])->assertOk();
        $this->assertSame(['Calle', 'Mazda'], [$car->fresh()->location, $car->fresh()->brand]);
        PriceTrackServer::tool(SaveAsset::class, ['id' => $car->id, 'valuation_method' => 'straight_line'])->assertHasErrors(['vida útil']);

        PriceTrackServer::tool(SaveMaintenancePlan::class, ['asset_id' => $car->id, 'name' => 'Cambio de aceite', 'every' => 6, 'every_unit' => 'month', 'every_meter' => 10000])->assertOk();
        $plan = MaintenancePlan::firstOrFail();
        $this->assertNotNull($plan->task_id, 'El plan deja su recordatorio en Tareas');

        PriceTrackServer::tool(RecordAsset::class, ['asset_id' => $car->id, 'type' => 'value', 'amount' => 300000])->assertOk();
        PriceTrackServer::tool(RecordAsset::class, ['asset_id' => $car->id, 'type' => 'reading', 'amount' => 45000])->assertOk();
        PriceTrackServer::tool(RecordAsset::class, ['asset_id' => $car->id, 'type' => 'service', 'plan_id' => $plan->id, 'cost' => 1200, 'amount' => 45000, 'provider' => 'Agencia'])->assertOk();
        $this->assertSame([300000.0, 45000.0], [$car->fresh()->currentValue(), $car->fresh()->currentMeter()]);

        PriceTrackServer::tool(GetAsset::class, ['asset_id' => $car->id])->assertOk()->assertSee('Cambio de aceite')->assertSee('Agencia')->assertSee('VIN123');

        $service = $car->maintenanceLogs()->firstOrFail();
        PriceTrackServer::tool(DeleteAssetRecord::class, ['type' => 'service', 'id' => $service->id])->assertOk();
        PriceTrackServer::tool(DeleteAssetRecord::class, ['type' => 'plan', 'id' => $plan->id])->assertOk();
        $this->assertSame(0, Task::pending()->count(), 'Borrar el plan quita su tarea');

        // Venderlo
        PriceTrackServer::tool(SaveAsset::class, ['id' => $car->id, 'status' => 'sold', 'disposed_on' => '2026-11-01', 'disposal_amount' => 280000])->assertOk();
        $this->assertSame('sold', $car->fresh()->status);
        PriceTrackServer::tool(DeleteAssetRecord::class, ['type' => 'asset', 'id' => $car->id])->assertOk();
        $this->assertSame(0, Asset::count());
    }

    public function test_task_lists_are_created_renamed_and_deleted(): void
    {
        PriceTrackServer::tool(SaveTaskList::class, ['name' => 'Jardín'])->assertOk();
        $list = TaskList::firstOrFail();
        Task::create(['title' => 'Podar', 'task_list_id' => $list->id]);
        PriceTrackServer::tool(SaveTaskList::class, ['id' => $list->id, 'name' => 'Patio'])->assertOk();
        $this->assertSame('Patio', $list->fresh()->name);
        PriceTrackServer::tool(SaveTaskList::class, ['name' => 'Patio'])->assertHasErrors(['nombre de la lista']);

        PriceTrackServer::tool(DeleteTaskList::class, ['id' => $list->id])->assertOk()->assertSee('tasks_moved_to_general');
        $this->assertNull(Task::firstOrFail()->task_list_id);
    }

    public function test_accounts_and_debts_are_created_and_their_records_corrected_or_deleted(): void
    {
        PriceTrackServer::tool(SaveInvestmentAccount::class, ['name' => 'CETES', 'type' => 'cetes', 'institution' => 'Cetesdirecto'])->assertOk();
        $account = InvestmentAccount::firstOrFail();
        $this->assertSame('MXN', $account->currency);
        PriceTrackServer::tool(SaveDebt::class, ['name' => 'Tarjeta', 'type' => 'card', 'original_amount' => 20000, 'annual_rate' => 40])->assertOk();
        $this->assertSame(1, Debt::count());

        PriceTrackServer::tool(RecordInvestment::class, ['account_id' => $account->id, 'type' => 'deposit', 'amount' => 1000])->assertOk();
        PriceTrackServer::tool(SaveInvestmentAccount::class, ['id' => $account->id, 'currency' => 'USD'])->assertHasErrors(['ya tiene registros']);

        PriceTrackServer::tool(GetFinanceRecords::class, ['investment_account_id' => $account->id])->assertOk()->assertSee('investment_movement');
        $movement = InvestmentTransaction::firstOrFail();
        PriceTrackServer::tool(CorrectFinanceRecord::class, ['type' => 'investment_movement', 'id' => $movement->id, 'amount' => 1500, 'kind' => 'deposit'])->assertOk();
        $this->assertSame(1500.0, (float) $movement->fresh()->amount);
        PriceTrackServer::tool(CorrectFinanceRecord::class, ['type' => 'investment_movement', 'id' => $movement->id, 'kind' => 'otro'])->assertHasErrors();
        PriceTrackServer::tool(DeleteFinanceRecord::class, ['type' => 'investment_movement', 'id' => $movement->id])->assertOk();
        $this->assertSame(0, InvestmentTransaction::count());

        // Un ingreso de una recurrente: corregirlo y borrarlo regresa la fecha
        $salary = IncomeSource::create(['name' => 'Sueldo', 'amount' => 10000, 'every' => 1, 'every_unit' => 'month', 'next_on' => '2026-11-15']);
        PriceTrackServer::tool(RecordIncome::class, ['income_source_id' => $salary->id])->assertOk();
        $income = Income::firstOrFail();
        PriceTrackServer::tool(CorrectFinanceRecord::class, ['type' => 'income', 'id' => $income->id, 'amount' => 9800, 'notes' => 'con descuento'])->assertOk();
        $this->assertSame(9800.0, $income->fresh()->amount);
        PriceTrackServer::tool(DeleteFinanceRecord::class, ['type' => 'income', 'id' => $income->id])->assertOk();
        $this->assertSame('2026-11-15', $salary->fresh()->next_on->toDateString());

        PriceTrackServer::tool(GetFinanceRecords::class, [])->assertHasErrors();
    }

    public function test_a_new_maintenance_plan_can_start_from_the_last_time_it_was_done(): void
    {
        Carbon::setTestNow('2026-11-19 10:00');
        $car = Asset::create(['name' => 'Auto', 'status' => 'active', 'valuation_method' => 'none', 'purchased_on' => '2020-01-10', 'meter_unit' => 'km']);

        // Sin la última vez se cuenta desde la compra: nace vencido
        PriceTrackServer::tool(SaveMaintenancePlan::class, ['asset_id' => $car->id, 'name' => 'Rotar llantas', 'every' => 6, 'every_unit' => 'month'])->assertOk();
        $this->assertSame('overdue', MaintenancePlan::where('name', 'Rotar llantas')->firstOrFail()->status());

        PriceTrackServer::tool(SaveMaintenancePlan::class, [
            'asset_id' => $car->id, 'name' => 'Cambio de aceite', 'every' => 6, 'every_unit' => 'month', 'every_meter' => 10000,
            'last_done_on' => '2026-09-01', 'last_meter' => 40000,
        ])->assertOk()->assertSee('2026-09-01');

        $plan = MaintenancePlan::where('name', 'Cambio de aceite')->firstOrFail();
        $this->assertSame('2027-03-01', $plan->nextDueOn()->toDateString());
        $this->assertSame(50000.0, (float) $plan->nextMeter());
        $this->assertSame('ok', $plan->status());
        $this->assertSame('2027-03-01', Task::findOrFail($plan->task_id)->due_on->toDateString(), 'la tarea se mueve a la siguiente fecha');

        // En uno existente también; la misma fecha no duplica el servicio
        PriceTrackServer::tool(SaveMaintenancePlan::class, ['id' => $plan->id, 'last_done_on' => '2026-09-01', 'last_meter' => 41000])->assertOk();
        $this->assertSame(1, $plan->logs()->count());
        $this->assertSame(41000.0, (float) $plan->logs()->first()->meter_reading);

        PriceTrackServer::tool(SaveMaintenancePlan::class, ['id' => $plan->id, 'last_done_on' => '2026-12-01'])->assertHasErrors();
        PriceTrackServer::tool(SaveMaintenancePlan::class, ['id' => $plan->id, 'last_meter' => 5])->assertHasErrors();
    }
}
