<?php

namespace App\Http\Controllers;

use App\Models\Income;
use App\Models\IncomeSource;
use App\Services\IncomeReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'received_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'kind' => ['required', Rule::in(array_keys(Income::KINDS))],
            'category' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['name' => 'concepto', 'received_on' => 'fecha', 'amount' => 'monto', 'kind' => 'tipo', 'category' => 'categoría']);
        $data['category'] = Income::canonicalCategory($data['category'] ?? null);
        $income = Income::create($data);

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
        $data = $request->validate([
            'received_on' => ['nullable', 'date', 'before_or_equal:today'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:99999999999'],
        ], [], ['received_on' => 'fecha', 'amount' => 'monto']);

        $due = $incomeSource->next_on;
        $income = $incomeSource->markReceived(
            isset($data['received_on']) ? CarbonImmutable::parse($data['received_on']) : null,
            isset($data['amount']) ? (float) $data['amount'] : null,
        );

        return back()->with('success', "«{$incomeSource->name}» del {$due->translatedFormat('d M')} recibido (".money($income->amount).'). El siguiente: '.$incomeSource->next_on->translatedFormat('d M Y').'.');
    }

    private function validatedSource(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'kind' => ['required', Rule::in(array_keys(Income::KINDS))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'every' => ['required', 'integer', 'min:1', 'max:999'],
            'every_unit' => ['required', Rule::in(array_keys(IncomeSource::scheduleUnits(true)))],
            'next_on' => ['required', 'date'],
            'active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], [
            'name' => 'concepto', 'category' => 'categoría', 'kind' => 'tipo', 'amount' => 'monto',
            'every' => 'cada cuánto', 'every_unit' => 'unidad', 'next_on' => 'próxima fecha',
        ]);
        $data['category'] = Income::canonicalCategory($data['category'] ?? null);
        $data['active'] = $request->boolean('active', true);

        return $data;
    }
}
