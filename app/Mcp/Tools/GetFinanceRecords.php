<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Debt;
use App\Models\DebtBalance;
use App\Models\Income;
use App\Models\IncomeSource;
use App\Models\InvestmentAccount;
use App\Models\InvestmentTransaction;
use App\Models\InvestmentValuation;
use App\Models\Payment;
use App\Models\RecurringPayment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Los registros con su `id` (para corregirlos con correct-finance-record o borrarlos con delete-finance-record) de UNA cosa: una cuenta de inversión (`investment_account_id`: movimientos y valores), una deuda (`debt_id`: saldos y pagos), un pago recurrente (`recurring_payment_id`: sus pagos con lectura, consumo y precio por unidad) o un ingreso recurrente (`income_source_id`: lo recibido). Los ingresos de un mes están en get-incomes.')]
#[IsReadOnly]
class GetFinanceRecords extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'investment_account_id' => ['nullable', 'integer'],
            'debt_id' => ['nullable', 'integer'],
            'recurring_payment_id' => ['nullable', 'integer'],
            'income_source_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $limit = $data['limit'] ?? 50;
        $payment = fn (Payment $p) => ['id' => $p->id, 'type' => 'payment', 'paid_on' => $p->paid_on->toDateString(), 'due_on' => $p->due_on?->toDateString(), 'amount' => $p->amount, 'reading' => $p->reading, 'notes' => $p->notes];

        if (! empty($data['investment_account_id'])) {
            $account = $this->find(InvestmentAccount::class, $data['investment_account_id'], 'una cuenta de inversión', ['transactions', 'valuations']);

            return $this->json([
                'account' => $account->only(['id', 'name', 'currency']),
                'movements' => $account->transactions->sortByDesc('occurred_on')->take($limit)->map(fn (InvestmentTransaction $t) => [
                    'id' => $t->id, 'type' => 'investment_movement', 'date' => $t->occurred_on->toDateString(), 'kind' => $t->kind, 'amount' => $t->amount, 'exchange_rate' => $t->exchange_rate, 'notes' => $t->notes,
                ])->values()->all(),
                'values' => $account->valuations->sortByDesc('valued_on')->take($limit)->map(fn (InvestmentValuation $v) => [
                    'id' => $v->id, 'type' => 'investment_value', 'date' => $v->valued_on->toDateString(), 'amount' => $v->value, 'exchange_rate' => $v->exchange_rate,
                ])->values()->all(),
            ]);
        }

        if (! empty($data['debt_id'])) {
            $debt = $this->find(Debt::class, $data['debt_id'], 'una deuda', ['balances', 'payments']);

            return $this->json([
                'debt' => $debt->only(['id', 'name']) + ['balance' => $debt->currentBalance()],
                'balances' => $debt->balances->sortByDesc('balance_on')->take($limit)->map(fn (DebtBalance $b) => [
                    'id' => $b->id, 'type' => 'debt_balance', 'date' => $b->balance_on->toDateString(), 'amount' => $b->balance,
                ])->values()->all(),
                'payments' => $debt->payments->sortByDesc('paid_on')->take($limit)->map($payment)->values()->all(),
            ]);
        }

        if (! empty($data['recurring_payment_id'])) {
            $recurring = $this->find(RecurringPayment::class, $data['recurring_payment_id'], 'un pago recurrente', ['payments']);

            return $this->json([
                'recurring_payment' => $recurring->only(['id', 'name', 'amount', 'meter_unit']),
                'payments' => collect($recurring->history())->take($limit)->map(fn (array $row) => $payment($row['payment']) + [
                    'consumption' => $row['consumption'], 'unit_price' => $row['unit_price'],
                ])->all(),
            ]);
        }

        if (! empty($data['income_source_id'])) {
            $source = $this->find(IncomeSource::class, $data['income_source_id'], 'un ingreso recurrente');

            return $this->json([
                'income_source' => $source->only(['id', 'name', 'amount', 'kind']),
                'incomes' => $source->incomes()->take($limit)->get()->map(fn (Income $i) => [
                    'id' => $i->id, 'type' => 'income', 'date' => $i->received_on->toDateString(), 'due_on' => $i->due_on?->toDateString(), 'amount' => $i->amount, 'kind' => $i->kind, 'notes' => $i->notes,
                ])->all(),
            ]);
        }

        return Response::error('Indica de qué: investment_account_id, debt_id, recurring_payment_id o income_source_id (los id salen de get-finances y get-incomes).');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'investment_account_id' => $schema->integer(),
            'debt_id' => $schema->integer(),
            'recurring_payment_id' => $schema->integer(),
            'income_source_id' => $schema->integer(),
            'limit' => $schema->integer()->description('Cuántos de cada tipo (50 por defecto).'),
        ];
    }
}
