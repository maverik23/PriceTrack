<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Payment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Corrige un pago ya registrado (`payment_id`, de get-finances → last_payment): monto, fecha, lectura del medidor o notas. Solo cambia lo que se manda.')]
class UpdatePayment extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'payment_id' => ['required', 'integer'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'reading' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['paid_on' => 'fecha de pago', 'amount' => 'monto', 'reading' => 'lectura']);

        $payment = $this->find(Payment::class, $data['payment_id'], 'un pago');
        $payment->update(array_filter([
            'paid_on' => isset($data['paid_on']) ? $this->date($data['paid_on'])->toDateString() : null,
            'amount' => $data['amount'] ?? null,
            'reading' => $data['reading'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], fn ($v) => $v !== null));

        return $this->json([
            'payment_id' => $payment->id,
            'paid_on' => $payment->paid_on->toDateString(),
            'amount' => $payment->amount,
            'reading' => $payment->reading,
            'consumption' => $payment->consumption(),
            'unit_price' => $payment->unitPrice(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'payment_id' => $schema->integer()->description('Id del pago.')->required(),
            'paid_on' => $schema->string()->description('Fecha AAAA-MM-DD.'),
            'amount' => $schema->number()->description('Monto correcto.'),
            'reading' => $schema->number()->description('Lectura final del medidor.'),
            'notes' => $schema->string(),
        ];
    }
}
