<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\InvestmentAccount;
use App\Models\InvestmentTransaction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Registra algo en una cuenta de inversión (`account_id`, de get-finances): un movimiento (`type`: deposit = aportación, withdrawal = retiro, income = dividendo o interés, fee = comisión; `amount` positivo en la moneda de la cuenta) o el valor de la cuenta ese día (`type: valuation`, `amount` = el valor; uno por día, se corrige si ya había). En cuentas en otra moneda hace falta `exchange_rate` (pesos por unidad).')]
class RecordInvestment extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'type' => ['required', Rule::in([...array_keys(InvestmentTransaction::KINDS), 'valuation'])],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['type' => 'tipo', 'amount' => 'monto', 'date' => 'fecha', 'exchange_rate' => 'tipo de cambio']);

        $account = $this->find(InvestmentAccount::class, $data['account_id'], 'una cuenta de inversión');
        if ($account->isForeign() && empty($data['exchange_rate'])) {
            return Response::error("«{$account->name}» está en {$account->currency}: manda exchange_rate (pesos por unidad).");
        }
        $rate = $account->isForeign() ? (float) $data['exchange_rate'] : 1.0;
        $date = ($this->date($data['date'] ?? null) ?? today())->toDateString();

        if ($data['type'] === 'valuation') {
            $values = ['valued_on' => $date, 'value' => $data['amount'], 'exchange_rate' => $rate];
            $existing = $account->valuations()->whereDate('valued_on', $date)->first();
            $existing ? $existing->update($values) : $account->valuations()->create($values);
        } else {
            if (! ($data['amount'] > 0)) {
                return Response::error('El monto del movimiento debe ser mayor que cero.');
            }
            $account->transactions()->create([
                'occurred_on' => $date, 'kind' => $data['type'], 'amount' => $data['amount'], 'exchange_rate' => $rate, 'notes' => $data['notes'] ?? null,
            ]);
        }
        $account->refresh();

        return $this->json([
            'recorded' => $data['type'],
            'account' => $account->name,
            'value_now' => round($account->valueOn(today()), 2),
            'gain' => $account->gain(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->integer()->description('Cuenta de inversión (de get-finances).')->required(),
            'type' => $schema->string()->enum([...array_keys(InvestmentTransaction::KINDS), 'valuation'])->description('deposit, withdrawal, income, fee o valuation (el valor de la cuenta).')->required(),
            'amount' => $schema->number()->description('Monto del movimiento o valor de la cuenta, en su moneda.')->required(),
            'date' => $schema->string()->description('AAAA-MM-DD (hoy por defecto).'),
            'exchange_rate' => $schema->number()->description('Pesos por unidad, solo en cuentas en otra moneda.'),
            'notes' => $schema->string(),
        ];
    }
}
