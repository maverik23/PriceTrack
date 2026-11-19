<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Debt;
use App\Models\InvestmentAccount;
use App\Models\RecurringPayment;
use App\Services\FinanceReport;
use Carbon\CarbonImmutable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Finanzas del hogar en pesos: patrimonio neto (inversiones + bienes − deudas) de hoy y al cierre del mes pasado, cuentas de inversión con su valor, ganancia y rendimiento anual (TIR), deudas con saldo y meses que faltan, y pagos recurrentes con su próxima fecha (overdue = vencido, soon = en 7 días).')]
#[IsReadOnly]
class GetFinances extends Tool
{
    public function handle(Request $request, FinanceReport $finance): Response
    {
        $today = CarbonImmutable::today();
        $accounts = $finance->accounts();
        $debts = $finance->debts();
        $investments = $finance->investmentSummary($accounts);
        $recurring = RecurringPayment::with('debt')->orderByDesc('active')->orderBy('next_due_on')->get();

        return $this->json([
            'net_worth' => $finance->netWorthOn($today, $accounts, null, $debts),
            'net_worth_last_month_end' => $finance->netWorthOn($today->subMonthNoOverflow()->endOfMonth()->startOfDay(), $accounts, null, $debts),
            'investments' => [
                'value' => $investments['value'],
                'contributed' => $investments['contributed'],
                'gain' => $investments['gain'],
                'annualized_return' => $investments['annualized'] === null ? null : round($investments['annualized'], 4),
                'accounts' => $accounts->map(fn (InvestmentAccount $a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'institution' => $a->institution,
                    'type' => $a->typeLabel(),
                    'currency' => $a->currency,
                    'closed' => $a->isClosed(),
                    'value_mxn' => $a->currentValue(),
                    'contributed_mxn' => $a->netContributed($a->asOf()),
                    'gain_mxn' => $a->gain(),
                    'annualized_return' => ($r = $a->annualizedReturn()) === null ? null : round($r, 4),
                    'valued_on' => $a->asOf()->toDateString(),
                    'stale' => $a->isStale(),
                ])->all(),
            ],
            'debts' => $debts->map(fn (Debt $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'lender' => $d->lender,
                'type' => $d->typeLabel(),
                'balance' => $d->currentBalance(),
                'annual_rate_pct' => $d->annual_rate,
                'monthly_payment' => $d->monthlyPayment(),
                'months_left' => $d->monthsLeft(),
                'paid_off' => $d->isPaidOff(),
            ])->all(),
            'recurring_payments' => [
                'monthly_total' => round($recurring->where('active', true)->sum(fn (RecurringPayment $p) => $p->monthlyAmount()), 2),
                'payments' => $recurring->map(fn (RecurringPayment $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'category' => $p->category,
                    'amount' => $p->amount,
                    'every' => $p->intervalLabel(),
                    'next_due_on' => $p->next_due_on->toDateString(),
                    'status' => $p->status(),
                    'debt' => $p->debt?->name,
                ])->all(),
            ],
        ]);
    }
}
