<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\RecurringPayment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Marca un pago recurrente como pagado: registra el pago (hoy y por su monto, salvo que se indique otra cosa) y pasa al siguiente periodo en la misma fecha. Si es la mensualidad de una deuda, baja su saldo.')]
class MarkPaymentPaid extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'recurring_payment_id' => ['required', 'integer'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['paid_on' => 'fecha de pago', 'amount' => 'monto']);

        $recurring = $this->find(RecurringPayment::class, $data['recurring_payment_id'], 'un pago recurrente');
        if (! $recurring->active) {
            return Response::error("«{$recurring->name}» está inactivo.");
        }

        $payment = $recurring->markPaid(
            $this->date($data['paid_on'] ?? null),
            isset($data['amount']) ? (float) $data['amount'] : null,
            $data['notes'] ?? null,
        );

        return $this->json([
            'paid' => [
                'payment_id' => $payment->id,
                'name' => $recurring->name,
                'amount' => (float) $payment->amount,
                'paid_on' => $payment->paid_on->toDateString(),
                'covered_due_date' => $payment->due_on->toDateString(),
            ],
            'next_due_on' => $recurring->next_due_on->toDateString(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'recurring_payment_id' => $schema->integer()->description('Id del pago recurrente (de get-finances).')->required(),
            'paid_on' => $schema->string()->description('Fecha en que se pagó, AAAA-MM-DD (hoy por defecto).'),
            'amount' => $schema->number()->description('Lo que se pagó, si fue distinto al monto habitual.'),
            'notes' => $schema->string(),
        ];
    }
}
