<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Debt;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Registra algo en una deuda (`debt_id`, de get-finances): el saldo que dice el estado de cuenta (`type: balance`; uno por día, se corrige si ya había) o un pago suelto / abono a capital (`type: payment`). Las mensualidades fijas se registran con mark-payment-paid.')]
class RecordDebt extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'debt_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(['balance', 'payment'])],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['type' => 'tipo', 'amount' => 'monto', 'date' => 'fecha']);

        $debt = $this->find(Debt::class, $data['debt_id'], 'una deuda');
        $date = ($this->date($data['date'] ?? null) ?? today())->toDateString();

        if ($data['type'] === 'balance') {
            $existing = $debt->balances()->whereDate('balance_on', $date)->first();
            $existing ? $existing->update(['balance' => $data['amount']]) : $debt->balances()->create(['balance_on' => $date, 'balance' => $data['amount']]);
        } else {
            if (! ($data['amount'] > 0)) {
                return Response::error('El pago debe ser mayor que cero.');
            }
            $debt->payments()->create(['paid_on' => $date, 'amount' => $data['amount'], 'notes' => $data['notes'] ?? null]);
        }
        $debt->refresh();

        return $this->json([
            'recorded' => $data['type'],
            'debt' => $debt->name,
            'balance' => $debt->currentBalance(),
            'months_left' => $debt->monthsLeft(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'debt_id' => $schema->integer()->description('La deuda (de get-finances).')->required(),
            'type' => $schema->string()->enum(['balance', 'payment'])->description('balance (saldo del estado de cuenta) o payment (pago suelto o abono).')->required(),
            'amount' => $schema->number()->description('Saldo o monto del pago.')->required(),
            'date' => $schema->string()->description('AAAA-MM-DD (hoy por defecto).'),
            'notes' => $schema->string(),
        ];
    }
}
