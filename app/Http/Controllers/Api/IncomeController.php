<?php

namespace App\Http\Controllers\Api;

use App\Api\Present;
use App\Http\Controllers\IncomeController as WebIncome;
use App\Models\Income;
use App\Models\IncomeSource;
use App\Models\Payment;
use App\Services\IncomeReport;
use App\Support\IncomeData;
use App\Support\PaymentData;
use Illuminate\Http\Request;

/**
 * Ingresos y pagos para la app móvil: ingresos recurrentes y eventuales, lo pagado en el mes
 * (recurrentes, únicos y abonos), pagos únicos y el balance (IncomeData, PaymentData, IncomeReport).
 * Los pagos recurrentes están en RecurringPaymentController.
 */
class IncomeController extends Controller
{
    /**
     * El mes (`month=AAAA-MM`; el actual por defecto): lo recibido (dinero y en especie, por
     * categoría), lo gastado (súper y pagos), el balance, la serie de 12 meses, el promedio, lo
     * esperado al mes, los ingresos recurrentes y lo pagado en el mes (`paid`).
     */
    public function index(Request $request, IncomeReport $report)
    {
        $month = WebIncome::month($request);
        $r = $report->month($month);
        $series = $report->series($month);
        $paymentSeries = $report->paymentSeries($month);
        $sources = IncomeSource::orderByDesc('active')->orderBy('next_on')->get();

        return response()->json(['data' => [
            'month' => $month->format('Y-m'),
            'label' => ucfirst($month->translatedFormat('F Y')),
            'prev' => $month->subMonth()->format('Y-m'),
            'next' => $month->addMonth()->format('Y-m'),
            'is_current_month' => $month->isSameMonth(today()),
            'total' => round($r['total'], 2),
            'money' => round($r['money'], 2),
            'in_kind' => round($r['inKind'], 2),
            'by_category' => $r['byCategory']->map(fn ($v) => round($v, 2)),
            'spent' => ['super' => round($r['super'], 2), 'payments' => round($r['payments'], 2), 'total' => round($r['spent'], 2)],
            'balance' => round($r['balance'], 2),
            'incomes' => $r['incomes']->map(fn (Income $i) => Present::income($i))->values(),
            'series' => $series->map(fn ($m) => ['month' => $m->key, 'label' => $m->label, 'money' => $m->money, 'in_kind' => $m->in_kind, 'total' => $m->total])->values(),
            'average' => IncomeReport::average($series),
            'expected' => $report->expectedMonthly(),
            'sources' => $sources->where('active', true)->map(fn (IncomeSource $s) => Present::incomeSource($s))->values(),
            'inactive' => $sources->where('active', false)->map(fn (IncomeSource $s) => Present::incomeSource($s))->values(),
            'categories' => Income::categories(),
            'paid' => [
                'total' => round($r['payments'], 2),
                'recurring' => round($r['paymentsRecurring'], 2),
                'one_time' => round($r['paymentsOneTime'], 2),
                'debt' => round($r['paymentsDebt'], 2),
                'by_category' => $r['paymentsByCategory']->map(fn ($v) => round($v, 2)),
                'payments' => $r['paid']->map(fn (Payment $p) => Present::payment($p))->values(),
                'series' => $paymentSeries->map(fn ($m) => ['month' => $m->key, 'label' => $m->label, 'recurring' => $m->recurring, 'one_time' => $m->one_time, 'total' => $m->total])->values(),
                'expected_monthly' => $report->expectedPayments(),
                'categories' => Payment::categories(),
            ],
            'kinds' => Income::KINDS,
            'units' => self::units(IncomeSource::scheduleUnits(true)),
        ]]);
    }

    /** Un ingreso eventual: `name`, `received_on`, `amount`, `kind` (money, in_kind), `category?`, `notes?`. */
    public function store(Request $request)
    {
        $income = Income::create(IncomeData::validate($request->all()));

        return response()->json(['data' => Present::income($income), 'message' => "Ingreso «{$income->name}» de ".money($income->amount).' registrado.'], 201);
    }

    /** Un pago único: `name`, `amount`, `paid_on` (hasta hoy), `category?`, `notes?`. */
    public function storePayment(Request $request)
    {
        $payment = PaymentData::create($request->all());

        return response()->json(['data' => Present::payment($payment), 'message' => "Pago «{$payment->name}» de ".money($payment->amount).' registrado.'], 201);
    }

    /** Deshace un ingreso (si vino de una recurrente, su fecha regresa). */
    public function destroy(Income $income)
    {
        $income->source ? $income->source->undo($income) : $income->delete();

        return response()->json(['message' => 'Ingreso eliminado.']);
    }

    public function storeSource(Request $request)
    {
        $source = IncomeSource::create(IncomeData::validateSource($request->all()));

        return response()->json(['data' => Present::incomeSource($source), 'message' => "Ingreso recurrente «{$source->name}» agregado."], 201);
    }

    /** Solo cambia lo que se manda (`active: false` lo pausa). */
    public function updateSource(Request $request, IncomeSource $incomeSource)
    {
        $incomeSource->update(IncomeData::validateSource($request->all() + IncomeData::sourceInput($incomeSource)));

        return response()->json(['data' => Present::incomeSource($incomeSource), 'message' => 'Ingreso recurrente actualizado.']);
    }

    public function destroySource(IncomeSource $incomeSource)
    {
        $incomeSource->delete();

        return response()->json(['message' => 'Ingreso recurrente eliminado (lo recibido se conserva).']);
    }

    /** Marca recibido el periodo que toca: `received_on?`, `amount?` (vacío = el de siempre). */
    public function receive(Request $request, IncomeSource $incomeSource)
    {
        $due = $incomeSource->next_on;
        $income = IncomeData::receive($incomeSource, $request->all());

        return response()->json([
            'data' => Present::incomeSource($incomeSource->fresh()),
            'income' => Present::income($income),
            'message' => "«{$incomeSource->name}» del {$due->translatedFormat('d M')} recibido (".money($income->amount).'). El siguiente: '.$incomeSource->fresh()->next_on->translatedFormat('d M Y').'.',
        ], 201);
    }
}
