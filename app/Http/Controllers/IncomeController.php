<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Models\Income;
use App\Models\IncomeSource;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Services\IncomeReport;
use App\Support\IncomeData;
use App\Support\PaymentData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Ingresos y pagos, en un solo lugar: lo que entra (sueldo, renta, vales; ventas, bonos,
 * regalos) y lo que sale (luz, agua, internet, mensualidades; gasolina, una comida fuera),
 * cada uno recurrente o único, con el balance del mes.
 */
class IncomeController extends Controller
{
    /** Pestañas de la pantalla (`?tab=`): ingresos (por defecto) o pagos. */
    public const TABS = ['incomes' => 'Ingresos', 'payments' => 'Pagos'];

    /** El mes (?month=AAAA-MM; por defecto el actual): lo recibido, lo pagado, lo esperado y el balance. */
    public function index(Request $request, IncomeReport $report)
    {
        $month = self::month($request);
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'incomes';
        $series = $report->series($month);
        $sources = IncomeSource::orderByDesc('active')->orderBy('next_on')->get();
        $recurring = RecurringPayment::with(['debt', 'payments'])->orderBy('next_due_on')->get();

        return view('incomes.index', [
            'tab' => $tab,
            'report' => $report->month($month),
            'series' => $series,
            'paymentSeries' => $report->paymentSeries($month),
            'average' => IncomeReport::average($series),
            'expected' => $report->expectedMonthly(),
            'expectedPayments' => $report->expectedPayments(),
            'sources' => $sources->where('active', true)->values(),
            'inactive' => $sources->where('active', false)->values(),
            'categories' => Income::categories(),
            'units' => IncomeSource::scheduleUnits(true),
            'source' => new IncomeSource(['next_on' => today()]),
            'recurring' => $recurring->where('active', true)->values(),
            'recurringInactive' => $recurring->where('active', false)->values(),
            'paymentCategories' => Payment::categories(),
            'debts' => Debt::orderBy('name')->get()->reject->isPaidOff(),
            'payment' => new RecurringPayment(['next_due_on' => today()]),
            'isCurrentMonth' => $month->isSameMonth(today()),
        ]);
    }

    /** El mes pedido (`month=AAAA-MM`) o el actual. */
    public static function month(Request $request): CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? CarbonImmutable::createFromFormat('!Y-m', $request->query('month'))
            : CarbonImmutable::today()->startOfMonth();
    }

    /** Un pago único (gasolina, una comida fuera, una compra). */
    public function storePayment(Request $request)
    {
        $payment = PaymentData::create($request->all());

        return redirect()->route('incomes.index', ['tab' => 'payments', 'month' => $payment->paid_on->format('Y-m')])
            ->with('success', "Pago «{$payment->name}» de ".money($payment->amount).' registrado.');
    }

    /** Un ingreso eventual (bono, venta, regalo…). */
    public function store(Request $request)
    {
        $income = Income::create(IncomeData::validate($request->all()));

        return redirect()->route('incomes.index', ['month' => $income->received_on->format('Y-m')])
            ->with('success', "Ingreso «{$income->name}» de ".money($income->amount).' registrado.');
    }

    /** Deshace un ingreso (si vino de una recurrente, su fecha regresa). */
    public function destroy(Income $income)
    {
        $income->source ? $income->source->undo($income) : $income->delete();

        return back()->with('success', 'Ingreso eliminado.');
    }

    // ---------- Recurrentes ----------

    public function storeSource(Request $request)
    {
        $source = IncomeSource::create($this->validatedSource($request));

        return redirect()->route('incomes.index')->with('success', "Ingreso recurrente «{$source->name}» agregado.");
    }

    public function editSource(IncomeSource $incomeSource)
    {
        return view('incomes.edit-source', [
            'source' => $incomeSource,
            'categories' => Income::categories(),
            'units' => IncomeSource::scheduleUnits(true),
        ]);
    }

    public function updateSource(Request $request, IncomeSource $incomeSource)
    {
        $incomeSource->update($this->validatedSource($request));

        return redirect()->route('incomes.index')->with('success', 'Ingreso recurrente actualizado.');
    }

    public function destroySource(IncomeSource $incomeSource)
    {
        $incomeSource->delete();

        return redirect()->route('incomes.index')->with('success', 'Ingreso recurrente eliminado (lo recibido se conserva).');
    }

    /** Marca recibido el periodo que toca, con el monto de siempre o el que se indique. */
    public function receive(Request $request, IncomeSource $incomeSource)
    {
        $due = $incomeSource->next_on;
        $income = IncomeData::receive($incomeSource, $request->all());

        return back()->with('success', "«{$incomeSource->name}» del {$due->translatedFormat('d M')} recibido (".money($income->amount).'). El siguiente: '.$incomeSource->next_on->translatedFormat('d M Y').'.');
    }

    private function validatedSource(Request $request): array
    {
        return IncomeData::validateSource($request->all());
    }
}
