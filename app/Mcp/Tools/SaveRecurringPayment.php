<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\RecurringPayment;
use App\Support\Categories;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Crea un pago recurrente (luz, agua, internet, una suscripción, la mensualidad de una deuda) o, con `id`, cambia uno existente (solo lo que se manda; `active: false` lo pausa). `every_unit: fortnight` = quincenal (el 15 y el último día del mes). `meter_unit` (m³, kWh) si el recibo trae lectura de medidor. Para registrar que se pagó un recibo usa mark-payment-paid.')]
class SaveRecurringPayment extends Tool
{
    public function handle(Request $request): Response
    {
        $creating = ! $request->get('id');
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'name' => [$req, 'string', 'max:120'],
            'amount' => [$req, 'numeric', 'gt:0', 'max:99999999999'],
            'next_due_on' => [$req, 'date'],
            'every' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'every_unit' => ['sometimes', Rule::in(array_keys(RecurringPayment::scheduleUnits(true)))],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'meter_unit' => ['sometimes', 'nullable', 'string', 'max:12'],
            'debt_id' => ['sometimes', 'nullable', 'exists:debts,id'],
            'active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [], ['name' => 'concepto', 'amount' => 'monto', 'next_due_on' => 'próximo pago', 'every_unit' => 'unidad']);

        $payment = $creating ? new RecurringPayment : $this->find(RecurringPayment::class, $data['id'], 'un pago recurrente');
        unset($data['id']);
        if (array_key_exists('category', $data)) {
            $data['category'] = RecurringPayment::canonicalCategory($data['category']);
        }
        if (isset($data['next_due_on'])) {
            $data['next_due_on'] = $this->date($data['next_due_on'])->toDateString();
        }
        $payment->fill($data)->save();

        return $this->json([
            'saved' => $creating ? 'created' : 'updated',
            'recurring_payment' => [
                'id' => $payment->id, 'name' => $payment->name, 'amount' => $payment->amount, 'every' => $payment->intervalLabel(),
                'next_due_on' => $payment->next_due_on->toDateString(), 'meter_unit' => $payment->meter_unit, 'active' => $payment->active,
            ],
        ], Categories::notices($request->all(), ['category' => [$payment->category, 'payment_category']]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Para cambiar uno existente (de get-finances). Sin él se crea.'),
            'name' => $schema->string()->description('Qué se paga: "Luz", "Internet".'),
            'amount' => $schema->number()->description('Monto de referencia.'),
            'next_due_on' => $schema->string()->description('Fecha del próximo recibo, AAAA-MM-DD.'),
            'every' => $schema->integer()->description('Cada cuánto (1 por defecto).'),
            'every_unit' => $schema->string()->enum(array_keys(RecurringPayment::scheduleUnits(true)))->description('day, week, fortnight (quincena: el 15 y el último día del mes), month (por defecto) o year.'),
            'category' => $schema->string()->description('Ej. Servicios, Suscripciones.'),
            'meter_unit' => $schema->string()->description('Unidad de la lectura del medidor si el recibo la trae: m³, kWh.'),
            'debt_id' => $schema->integer()->description('Si es la mensualidad de una deuda.'),
            'active' => $schema->boolean()->description('false lo pausa sin perder su historial.'),
            'notes' => $schema->string(),
        ];
    }
}
