<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\RecurringPayment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Marca un pago recurrente como pagado: registra el pago del recibo que toca (hoy y por su monto, salvo que se indique otro; el monto indicado queda como el de referencia porque luz y agua cambian cada mes) y pasa al siguiente periodo en la misma fecha. Si el pago lleva medidor (`meter_unit` en get-finances: m³, kWh), manda `reading` = la lectura final del recibo y se calcula el consumo del periodo y el precio por unidad. Si es la mensualidad de una deuda, baja su saldo.')]
class MarkPaymentPaid extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'recurring_payment_id' => ['required', 'integer'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'notes' => ['nullable', 'string', 'max:255'],
            'reading' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ], [], ['paid_on' => 'fecha de pago', 'amount' => 'monto', 'reading' => 'lectura']);

        $recurring = $this->find(RecurringPayment::class, $data['recurring_payment_id'], 'un pago recurrente');
        if (! $recurring->active) {
            return Response::error("«{$recurring->name}» está inactivo.");
        }

        $payment = $recurring->markPaid(
            $this->date($data['paid_on'] ?? null),
            isset($data['amount']) ? (float) $data['amount'] : null,
            $data['notes'] ?? null,
            isset($data['reading']) ? (float) $data['reading'] : null,
        );

        return $this->json([
            'paid' => [
                'payment_id' => $payment->id,
                'name' => $recurring->name,
                'amount' => (float) $payment->amount,
                'paid_on' => $payment->paid_on->toDateString(),
                'covered_due_date' => $payment->due_on->toDateString(),
                'reading' => $payment->reading,
                'consumption' => $payment->consumption(),
                'unit_price' => $payment->unitPrice(),
                'meter_unit' => $recurring->meter_unit,
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
            'reading' => $schema->number()->description('Lectura final del medidor en este recibo (en la unidad del pago: m³, kWh).'),
        ];
    }
}
