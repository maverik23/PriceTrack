<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Income;
use App\Models\IncomeSource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Crea un ingreso recurrente (sueldo, renta, vales de despensa cada mes) o, con `id`, cambia uno existente (solo lo que se manda; `active: false` lo pausa). `every_unit: fortnight` = quincenal (el 15 y el último día del mes). Para registrar que se recibió usa record-income con `income_source_id`.')]
class SaveIncomeSource extends Tool
{
    public function handle(Request $request): Response
    {
        $creating = ! $request->get('id');
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'name' => [$req, 'string', 'max:120'],
            'amount' => [$req, 'numeric', 'gt:0', 'max:99999999999'],
            'next_on' => [$req, 'date'],
            'kind' => ['sometimes', Rule::in(array_keys(Income::KINDS))],
            'every' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'every_unit' => ['sometimes', Rule::in(array_keys(IncomeSource::scheduleUnits(true)))],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [], ['name' => 'concepto', 'amount' => 'monto', 'next_on' => 'próxima fecha', 'kind' => 'tipo', 'every_unit' => 'unidad']);

        $source = $creating ? new IncomeSource : $this->find(IncomeSource::class, $data['id'], 'un ingreso recurrente');
        unset($data['id']);
        if (array_key_exists('category', $data)) {
            $data['category'] = Income::canonicalCategory($data['category']);
        }
        if (isset($data['next_on'])) {
            $data['next_on'] = $this->date($data['next_on'])->toDateString();
        }
        $source->fill($data)->save();

        return $this->json([
            'saved' => $creating ? 'created' : 'updated',
            'income_source' => [
                'id' => $source->id, 'name' => $source->name, 'amount' => $source->amount, 'kind' => $source->kind,
                'every' => $source->intervalLabel(), 'next_on' => $source->next_on->toDateString(), 'active' => $source->active,
                'monthly' => $source->monthlyAmount(),
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Para cambiar uno existente (de get-incomes). Sin él se crea.'),
            'name' => $schema->string()->description('Concepto: "Sueldo", "Vales de despensa".'),
            'amount' => $schema->number()->description('Monto o, en especie, lo que vale.'),
            'next_on' => $schema->string()->description('Próxima fecha en que llega, AAAA-MM-DD.'),
            'kind' => $schema->string()->enum(array_keys(Income::KINDS))->description('money (dinero, por defecto) o in_kind (en especie).'),
            'every' => $schema->integer()->description('Cada cuánto (1 por defecto).'),
            'every_unit' => $schema->string()->enum(array_keys(IncomeSource::scheduleUnits(true)))->description('day, week, fortnight (quincena), month (por defecto) o year.'),
            'category' => $schema->string(),
            'active' => $schema->boolean(),
            'notes' => $schema->string(),
        ];
    }
}
