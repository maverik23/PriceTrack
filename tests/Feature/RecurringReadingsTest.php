<?php

namespace Tests\Feature;

use App\Mcp\PriceTrackServer;
use App\Mcp\Tools\GetFinances;
use App\Mcp\Tools\MarkPaymentPaid;
use App\Mcp\Tools\UpdatePayment;
use App\Models\Payment;
use App\Models\RecurringPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecurringReadingsTest extends TestCase
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

    public function test_paying_with_amount_and_reading_shows_paid_consumption_and_unit_price(): void
    {
        $this->post(route('recurring.store'), [
            'name' => 'Agua', 'amount' => 300, 'meter_unit' => 'm³', 'every' => 1, 'every_unit' => 'month', 'next_due_on' => '2026-10-05',
        ])->assertSessionHasNoErrors();
        $water = RecurringPayment::firstOrFail();
        $this->assertSame('m³', $water->meter_unit);

        // Octubre: lectura 205; noviembre: 223 m³ y el recibo cambió a $450
        $this->post(route('recurring.pay', $water), ['reading' => 205, 'paid_on' => '2026-10-06'])->assertRedirect();
        $this->post(route('recurring.pay', $water), ['amount' => 450, 'reading' => 223, 'paid_on' => '2026-11-06'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '18 m³') && str_contains($m, '$25.00/m³'));

        $water->refresh();
        $this->assertSame(450.0, $water->amount, 'El último monto queda de referencia');
        $this->assertSame('2026-12-05', $water->next_due_on->toDateString());
        $this->assertTrue($water->isPaidUp());

        $last = $water->load('payments')->history()[0];
        $this->assertSame([223.0, 18.0, 25.0], [$last['payment']->reading, $last['consumption'], $last['unit_price']]);

        $this->get(route('incomes.index', ['tab' => 'payments']))->assertOk()->assertSee('✓ Pagado')->assertSee('$25.00/m³')->assertSee('Próximo recibo');
        $this->get(route('recurring.edit', $water))->assertOk()->assertSee('+18 m³');

        // Corregir el recibo: era 220
        $payment = Payment::where('reading', 223)->firstOrFail();
        $this->put(route('paid.update', $payment), ['paid_on' => '2026-11-06', 'amount' => 450, 'reading' => 220])->assertSessionHasNoErrors();
        $this->assertSame(15.0, $payment->fresh()->consumption());
        $this->assertSame(30.0, $payment->fresh()->unitPrice());
    }

    public function test_mcp_pays_with_reading_and_corrects_a_payment(): void
    {
        $power = RecurringPayment::create(['name' => 'Luz', 'amount' => 600, 'meter_unit' => 'kWh', 'every' => 2, 'every_unit' => 'month', 'next_due_on' => '2026-11-10']);
        $power->markPaid(Carbon::parse('2026-09-10'), null, null, 1000);

        PriceTrackServer::tool(MarkPaymentPaid::class, ['recurring_payment_id' => $power->id, 'amount' => 720, 'reading' => 1300])->assertOk()
            ->assertSee('consumption')->assertSee('unit_price');
        $payment = Payment::where('reading', 1300)->firstOrFail();
        $this->assertSame([300.0, 2.4], [$payment->consumption(), $payment->unitPrice()]);

        PriceTrackServer::tool(UpdatePayment::class, ['payment_id' => $payment->id, 'reading' => 1360])->assertOk();
        $this->assertSame(360.0, $payment->fresh()->consumption());
        PriceTrackServer::tool(GetFinances::class, [])->assertOk()->assertSee('kWh')->assertSee('1360');
    }

    public function test_utilities_without_a_unit_ask_for_the_reading_and_keep_the_unit_from_the_first_one(): void
    {
        $light = RecurringPayment::create(['name' => 'Luz (CFE)', 'category' => 'Servicios', 'amount' => 600, 'every' => 2, 'every_unit' => 'month', 'next_due_on' => '2026-10-10']);
        $netflix = RecurringPayment::create(['name' => 'Netflix', 'amount' => 219, 'every' => 1, 'every_unit' => 'month', 'next_due_on' => '2026-11-25']);
        $this->assertSame('kWh', $light->guessMeterUnit());
        $this->assertTrue($light->asksReading());
        $this->assertFalse($netflix->asksReading());

        // La lectura va junto al monto, con la unidad propuesta por el nombre
        $this->get(route('incomes.index', ['tab' => 'payments']))->assertOk()
            ->assertSee('placeholder="Lectura"', false)->assertSee('name="meter_unit" value="kWh"', false);

        // Una lectura sin unidad no se acepta; con ella, la unidad se queda en el pago
        $this->post(route('recurring.pay', $light), ['reading' => 1200, 'meter_unit' => ''])->assertSessionHasErrors('meter_unit');
        $this->post(route('recurring.pay', $light), ['amount' => 650, 'reading' => 1200, 'meter_unit' => 'kWh', 'paid_on' => '2026-10-11'])->assertSessionHasNoErrors();
        $this->post(route('recurring.pay', $light), ['amount' => 700, 'reading' => 1450, 'paid_on' => '2026-11-12'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '250 kWh') && str_contains($m, '$2.80/kWh'));
        $this->assertSame('kWh', $light->fresh()->meter_unit);

        // Pagar sin lectura sigue funcionando y no pide unidad
        $this->post(route('recurring.pay', $netflix))->assertSessionHasNoErrors();
    }
}
