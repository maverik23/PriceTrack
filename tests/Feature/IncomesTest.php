<?php

namespace Tests\Feature;

use App\Mcp\PriceTrackServer;
use App\Mcp\Tools\GetIncomes;
use App\Mcp\Tools\RecordIncome;
use App\Models\Income;
use App\Models\IncomeSource;
use App\Models\Payment;
use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ShoppingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IncomesTest extends TestCase
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

    public function test_fortnightly_salary_is_received_and_advances_to_the_15th_and_month_end(): void
    {
        $this->post(route('incomes.sources.store'), [
            'name' => 'Sueldo', 'category' => 'sueldo', 'kind' => 'money', 'amount' => 12000,
            'every' => 1, 'every_unit' => 'fortnight', 'next_on' => '2026-11-15',
        ])->assertSessionHasNoErrors();
        $salary = IncomeSource::firstOrFail();
        $this->assertSame('Sueldo', $salary->category, 'Reusa la categoría sugerida');
        $this->assertSame('Cada quincena', $salary->intervalLabel());
        $this->assertEqualsWithDelta(24000, $salary->monthlyAmount(), 0.01);

        // Llegó un poco menos (descuento): se registra lo que llegó y pasa al 30
        $this->post(route('incomes.sources.receive', $salary), ['amount' => 11800])->assertRedirect();
        $this->assertSame('2026-11-30', $salary->fresh()->next_on->toDateString());
        $this->assertSame(11800.0, Income::firstOrFail()->amount);
        $this->assertSame('2026-11-15', Income::firstOrFail()->due_on->toDateString());

        // Del 30 al 15 del mes siguiente y de ahí al fin de mes
        $this->assertSame('2026-12-15', $salary->fresh()->advance(Carbon::parse('2026-11-30'))->toDateString());
        $this->assertSame('2027-02-28', $salary->fresh()->advance(Carbon::parse('2027-02-15'))->toDateString());

        // Deshacer regresa la fecha
        $this->delete(route('incomes.destroy', Income::firstOrFail()))->assertRedirect();
        $this->assertSame('2026-11-15', $salary->fresh()->next_on->toDateString());
        $this->assertSame(0, Income::count());
    }

    public function test_month_shows_money_and_in_kind_incomes_against_what_was_spent(): void
    {
        IncomeSource::create(['name' => 'Vales de despensa', 'kind' => 'in_kind', 'amount' => 1500, 'every' => 1, 'every_unit' => 'month', 'next_on' => '2026-11-30']);
        Income::create(['name' => 'Sueldo', 'received_on' => '2026-11-15', 'kind' => 'money', 'amount' => 12000, 'category' => 'Sueldo']);
        $this->post(route('incomes.store'), ['name' => 'Regalo de cumpleaños', 'received_on' => '2026-11-10', 'amount' => 800, 'kind' => 'in_kind', 'category' => 'regalo'])
            ->assertRedirect(route('incomes.index', ['month' => '2026-11']));
        Income::create(['name' => 'Bono de octubre', 'received_on' => '2026-10-31', 'kind' => 'money', 'amount' => 3000]);

        // Gastado en noviembre: $500 de súper y un pago de $1,000
        $provider = Provider::create(['name' => 'Súper']);
        $rice = Product::create(['name' => 'Arroz', 'size' => 1, 'unit' => 'kg']);
        $price = Price::create(['product_id' => $rice->id, 'provider_id' => $provider->id, 'price' => 500, 'pack_qty' => 1, 'size' => 1, 'unit' => 'kg', 'observed_on' => '2026-11-12']);
        ShoppingList::create(['name' => 'Súper', 'shopped_on' => '2026-11-12'])->items()->create(['product_id' => $rice->id, 'quantity' => 1, 'price_id' => $price->id]);
        Payment::create(['paid_on' => '2026-11-05', 'amount' => 1000]);

        $response = $this->get(route('incomes.index'))->assertOk()->assertSee('Regalo de cumpleaños')->assertDontSee('Bono de octubre');
        $report = $response->viewData('report');
        $this->assertSame([12800.0, 12000.0, 800.0], [$report['total'], $report['money'], $report['inKind']]);
        $this->assertSame([500.0, 1000.0, 11300.0], [$report['super'], $report['payments'], $report['balance']]);
        $this->assertSame('Regalo', Income::where('name', 'Regalo de cumpleaños')->value('category'));
        $this->assertSame(['money' => 0.0, 'in_kind' => 1500.0, 'total' => 1500.0], $response->viewData('expected'));

        // Serie: octubre y noviembre; promedio desde el primer mes con ingresos
        $series = $response->viewData('series')->keyBy('key');
        $this->assertSame(3000.0, $series['2026-10']->total);
        $this->assertEqualsWithDelta((3000 + 12800) / 2, $response->viewData('average'), 0.01);

        $this->get(route('incomes.index', ['month' => '2026-10']))->assertOk()->assertSee('Bono de octubre');
        $this->post(route('incomes.store'), ['name' => '', 'received_on' => 'x', 'amount' => 0, 'kind' => 'otro'])->assertSessionHasErrors(['name', 'received_on', 'amount', 'kind']);
    }

    public function test_mcp_records_and_reads_incomes(): void
    {
        $source = IncomeSource::create(['name' => 'Sueldo', 'kind' => 'money', 'amount' => 12000, 'every' => 1, 'every_unit' => 'fortnight', 'next_on' => '2026-11-15']);

        PriceTrackServer::tool(RecordIncome::class, ['income_source_id' => $source->id])->assertOk()->assertSee('2026-11-30');
        PriceTrackServer::tool(RecordIncome::class, ['name' => 'Vales de gasolina', 'amount' => 700, 'kind' => 'in_kind'])->assertOk();
        PriceTrackServer::tool(RecordIncome::class, ['amount' => 5])->assertHasErrors(['concepto']);

        PriceTrackServer::tool(GetIncomes::class, [])->assertOk()
            ->assertSee('12700')->assertSee('Vales de gasolina')->assertSee('in_kind');
    }
}
