<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\DebtBalance;
use App\Models\Income;
use App\Models\InvestmentTransaction;
use App\Models\InvestmentValuation;
use App\Models\Payment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Borra un registro de finanzas: un movimiento o un valor de una cuenta de inversión, un saldo de deuda, un pago (si era de un pago recurrente, ese recibo vuelve a quedar pendiente) o un ingreso (si venía de un ingreso recurrente, su fecha regresa). Los `id` salen de get-finance-records o get-incomes. Confirma con la persona antes.')]
#[IsDestructive]
class DeleteFinanceRecord extends Tool
{
    private const TYPES = [
        'investment_movement' => [InvestmentTransaction::class, 'un movimiento de inversión'],
        'investment_value' => [InvestmentValuation::class, 'un valor de cuenta'],
        'debt_balance' => [DebtBalance::class, 'un saldo de deuda'],
        'payment' => [Payment::class, 'un pago'],
        'income' => [Income::class, 'un ingreso'],
    ];

    public function handle(Request $request): Response
    {
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(self::TYPES))], 'id' => ['required', 'integer']], [], ['type' => 'tipo']);
        [$class, $what] = self::TYPES[$data['type']];
        $record = $this->find($class, $data['id'], $what);

        match (true) {
            $record instanceof Payment && $record->recurringPayment !== null => $record->recurringPayment->undo($record),
            $record instanceof Income && $record->source !== null => $record->source->undo($record),
            default => $record->delete(),
        };

        return $this->json(['deleted' => $data['type'], 'id' => $data['id']]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(array_keys(self::TYPES))->required(),
            'id' => $schema->integer()->description('Id del registro.')->required(),
        ];
    }
}
