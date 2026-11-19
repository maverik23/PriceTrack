<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\InvestmentAccount;
use App\Models\MealPlanEntry;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_home_invites_to_start_every_section(): void
    {
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Empieza por donde quieras')
            ->assertSee('Nada pendiente para hoy')
            ->assertSee('No hay nada planeado para hoy');
    }

    public function test_home_summarizes_every_section(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 10:00'));

        MealPlanEntry::create(['planned_on' => '2026-11-19', 'meal' => 'dinner', 'title' => 'Tacos de canasta']);
        Task::create(['title' => 'Pagar el agua', 'due_on' => '2026-11-15']);
        Task::create(['title' => 'Regar', 'due_on' => '2026-11-19']);
        Task::create(['title' => 'Podar', 'due_on' => '2026-11-22']);
        Task::create(['title' => 'Hecha', 'due_on' => '2026-11-19', 'completed_at' => now()]);

        $refri = Asset::create(['name' => 'Refri', 'purchased_on' => '2025-01-01', 'purchase_price' => 20000, 'warranty_until' => '2026-12-10']);
        $refri->maintenancePlans()->create(['name' => 'Limpiar condensador', 'every' => 6, 'every_unit' => 'month']);

        $cetes = InvestmentAccount::create(['name' => 'CETES']);
        $cetes->transactions()->create(['occurred_on' => '2025-11-01', 'kind' => 'deposit', 'amount' => 10000]);
        $cetes->valuations()->create(['valued_on' => '2026-11-01', 'value' => 11000]);

        $this->get(route('dashboard'))->assertOk()
            ->assertDontSee('Empieza por donde quieras')
            ->assertSee('Tacos de canasta')
            ->assertSeeInOrder(['Pagar el agua', 'Regar'])
            ->assertDontSee('Podar')
            ->assertSee('1 vencida')
            ->assertSee('1 en los próximos 7 días')
            ->assertSee('Limpiar condensador')
            ->assertSee('Garantía hasta el 10 dic')
            // 20,000 del refri + 11,000 de CETES
            ->assertSee('$31,000');
    }
}
