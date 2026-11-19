<?php

namespace App\Http\Controllers;

use App\Models\Income;
use App\Models\IncomeSource;
use App\Services\IncomeReport;
use App\Support\IncomeData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Ingresos: recurrentes (sueldo, renta, vales) y eventuales, en dinero o en especie. */
class IncomeController extends Controller
{
    /** El mes (?month=AAAA-MM; por defecto el actual): lo recibido, lo esperado y el balance. */
    public function index(Request $request, IncomeReport $report)
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? CarbonImmutable::createFromFormat('!Y-m', $request->query('month'))
            : CarbonImmutable::today()->startOfMonth();
        $series = $report->series($month);
        $sources = IncomeSource::orderByDesc('active')->orderBy('next_on')->get();

        return view('incomes.index', [
            'report' => $report->month($month),
            'series' => $series,
            'average' => IncomeReport::average($series),
            'expected' => $report->expectedMonthly(),
            'sources' => $sources->where('active', true)->values(),
            'inactive' => $sources->where('active', false)->values(),
            'categories' => Income::categories(),
            'units' => IncomeSource::scheduleUnits(true),
            'source' => new IncomeSource(['next_on' => today()]),
            'isCurrentMonth' => $month->isSameMonth(today()),
        ]);
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
