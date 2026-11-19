<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Debt;
use App\Support\DebtData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Crea una deuda (hipoteca, crédito del auto, tarjeta, préstamo) o, con `id`, cambia una existente (solo lo que se manda; `paid_off_on` la marca liquidada). Su mensualidad fija es un pago recurrente (save-recurring-payment con `debt_id`); saldos y abonos con record-debt.')]
class SaveDebt extends Tool
{
    public function handle(Request $request): Response
    {
        $request->validate(['id' => ['nullable', 'integer']]);
        $debt = $request->get('id') ? $this->find(Debt::class, $request->get('id'), 'una deuda') : new Debt;
        $given = collect($request->all())->except('id')->all();
        $data = DebtData::validate($debt->exists ? array_merge(DebtData::toInput($debt), $given) : $given + ['type' => 'other']);

        $creating = ! $debt->exists;
        $debt->fill($data)->save();

        return $this->json(['saved' => $creating ? 'created' : 'updated', 'debt' => $debt->only(['id', 'name', 'lender', 'type', 'original_amount', 'annual_rate']) + ['balance' => $debt->currentBalance()]]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Para cambiar una existente (de get-finances). Sin él se crea.'),
            'name' => $schema->string()->description('Nombre: "Hipoteca", "Tarjeta Banamex".'),
            'lender' => $schema->string()->description('Acreedor.'),
            'type' => $schema->string()->enum(array_keys(Debt::TYPES))->description('mortgage, car, card, personal u other (por defecto).'),
            'original_amount' => $schema->number()->description('Monto original.'),
            'annual_rate' => $schema->number()->description('Tasa anual en %.'),
            'started_on' => $schema->string()->description('Fecha de inicio, AAAA-MM-DD.'),
            'paid_off_on' => $schema->string()->description('Fecha en que se liquidó.'),
            'notes' => $schema->string(),
        ];
    }
}
