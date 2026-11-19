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

#[Description('Corrige un registro de finanzas ya hecho (solo lo que se manda): un movimiento de inversión (`investment_movement`), un valor de cuenta (`investment_value`), un saldo de deuda (`debt_balance`), un pago (`payment`: también su lectura) o un ingreso (`income`: también concepto, categoría y si es en especie). Los `id` salen de get-finance-records o get-incomes.')]
class CorrectFinanceRecord extends Tool
{
    /** tipo => [modelo, qué es, columna de la fecha, columna del monto] */
    private const TYPES = [
        'investment_movement' => [InvestmentTransaction::class, 'un movimiento de inversión', 'occurred_on', 'amount'],
        'investment_value' => [InvestmentValuation::class, 'un valor de cuenta', 'valued_on', 'value'],
        'debt_balance' => [DebtBalance::class, 'un saldo de deuda', 'balance_on', 'balance'],
        'payment' => [Payment::class, 'un pago', 'paid_on', 'amount'],
        'income' => [Income::class, 'un ingreso', 'received_on', 'amount'],
    ];

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(self::TYPES))],
            'id' => ['required', 'integer'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'kind' => ['nullable', 'string'],
            'reading' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'name' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['type' => 'tipo', 'date' => 'fecha', 'amount' => 'monto', 'exchange_rate' => 'tipo de cambio', 'reading' => 'lectura']);

        [$class, $what, $dateColumn, $amountColumn] = self::TYPES[$data['type']];
        $record = $this->find($class, $data['id'], $what);

        $changes = [];
        if (isset($data['date'])) {
            $changes[$dateColumn] = $this->date($data['date'])->toDateString();
        }
        if (isset($data['amount'])) {
            $changes[$amountColumn] = $data['amount'];
        }
        $allowed = match ($data['type']) {
            'investment_movement' => ['exchange_rate', 'notes', 'kind'],
            'investment_value' => ['exchange_rate'],
            'payment' => ['reading', 'notes'],
            'income' => ['name', 'notes', 'kind', 'category'],
            default => [],
        };
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $changes[$field] = $data[$field];
            }
        }
        if (isset($changes['kind'])) {
            $kinds = $data['type'] === 'income' ? array_keys(Income::KINDS) : array_keys(InvestmentTransaction::KINDS);
            if (! in_array($changes['kind'], $kinds, true)) {
                return Response::error('kind debe ser uno de: '.implode(', ', $kinds).'.');
            }
        }
        if (isset($changes['category'])) {
            $changes['category'] = Income::canonicalCategory($changes['category']);
        }
        if (! $changes) {
            return Response::error('No mandaste nada que cambiar.');
        }
        $record->update($changes);

        return $this->json(['corrected' => $data['type'], 'id' => $record->id, 'changed' => array_keys($changes)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(array_keys(self::TYPES))->required(),
            'id' => $schema->integer()->description('Id del registro.')->required(),
            'date' => $schema->string()->description('Fecha correcta, AAAA-MM-DD.'),
            'amount' => $schema->number()->description('Monto, valor o saldo correcto.'),
            'exchange_rate' => $schema->number()->description('Inversiones en otra moneda.'),
            'kind' => $schema->string()->description('Movimiento: deposit, withdrawal, income, fee. Ingreso: money, in_kind.'),
            'reading' => $schema->number()->description('Pago: lectura del medidor.'),
            'name' => $schema->string()->description('Ingreso: concepto.'),
            'category' => $schema->string()->description('Ingreso: categoría.'),
            'notes' => $schema->string(),
        ];
    }
}
