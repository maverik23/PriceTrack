<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Support\Categories;
use App\Support\PaymentData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Registra un pago único (un gasto que no se repite: gasolina, una comida fuera, una compra, un regalo): `name`, `amount`, `paid_on` (hoy por defecto) y `category` opcional (se reusa la escritura existente: Gasolina, Comida fuera…). Cuenta en lo pagado del mes y en el balance de get-incomes. Para un recibo que se repite (luz, agua, internet) usa mark-payment-paid con su pago recurrente; para un abono a una deuda, record-debt. Lo súper se registra en las listas de compras, no aquí.')]
class RecordPayment extends Tool
{
    public function handle(Request $request): Response
    {
        $input = $request->all();
        $input['paid_on'] = filled($input['paid_on'] ?? null) ? $input['paid_on'] : today()->toDateString();
        $payment = PaymentData::create($input);

        return $this->json([
            'payment' => [
                'id' => $payment->id, 'name' => $payment->name, 'amount' => $payment->amount,
                'category' => $payment->category, 'paid_on' => $payment->paid_on->toDateString(), 'notes' => $payment->notes,
            ],
        ], Categories::notices($input, ['category' => [$payment->category, 'payment_category']]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Concepto: "Gasolina", "Cena con amigos".')->required(),
            'amount' => $schema->number()->description('Lo que se pagó, en pesos.')->required(),
            'paid_on' => $schema->string()->description('Fecha AAAA-MM-DD (hoy por defecto; no puede ser futura).'),
            'category' => $schema->string()->description('Ej. Gasolina, Comida fuera, Salud, Ropa.'),
            'notes' => $schema->string(),
        ];
    }
}
