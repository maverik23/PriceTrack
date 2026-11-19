<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Income;
use App\Models\IncomeSource;
use App\Support\Categories;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Registra un ingreso. Con `income_source_id` (de get-incomes) marca recibido ese ingreso recurrente (sueldo, vales…) por su monto habitual o el indicado y lo pasa a su siguiente fecha. Sin él, registra uno eventual: `name`, `amount` y `kind` (money = dinero, in_kind = en especie: vales, gasolina, un regalo; `amount` es lo que vale).')]
class RecordIncome extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'income_source_id' => ['nullable', 'integer'],
            'name' => ['required_without:income_source_id', 'nullable', 'string', 'max:120'],
            'amount' => ['required_without:income_source_id', 'nullable', 'numeric', 'gt:0', 'max:99999999999'],
            'kind' => ['nullable', Rule::in(array_keys(Income::KINDS))],
            'category' => ['nullable', 'string', 'max:60'],
            'received_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['name' => 'concepto', 'amount' => 'monto', 'kind' => 'tipo', 'received_on' => 'fecha']);

        if (! empty($data['income_source_id'])) {
            $source = $this->find(IncomeSource::class, $data['income_source_id'], 'un ingreso recurrente');
            if (! $source->active) {
                return Response::error("«{$source->name}» está pausado.");
            }
            $income = $source->markReceived($this->date($data['received_on'] ?? null), isset($data['amount']) ? (float) $data['amount'] : null, $data['notes'] ?? null);

            return $this->json(['income' => $this->present($income), 'next_on' => $source->next_on->toDateString()]);
        }

        $income = Income::create([
            'name' => $data['name'],
            'amount' => $data['amount'],
            'kind' => $data['kind'] ?? 'money',
            'category' => Income::canonicalCategory($data['category'] ?? null),
            'received_on' => ($this->date($data['received_on'] ?? null) ?? today())->toDateString(),
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->json(['income' => $this->present($income)], Categories::notices($data, ['category' => [$income->category, 'income_category']]));
    }

    private function present(Income $income): array
    {
        return [
            'id' => $income->id, 'name' => $income->name, 'amount' => $income->amount, 'kind' => $income->kind,
            'category' => $income->category, 'received_on' => $income->received_on->toDateString(),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'income_source_id' => $schema->integer()->description('Ingreso recurrente a marcar recibido (de get-incomes).'),
            'name' => $schema->string()->description('Concepto (si es eventual): "Bono", "Venta de la bici".'),
            'amount' => $schema->number()->description('Monto o, en especie, lo que vale. En un recurrente, solo si fue distinto al habitual.'),
            'kind' => $schema->string()->enum(array_keys(Income::KINDS))->description('money (dinero) o in_kind (en especie).'),
            'category' => $schema->string()->description('Ej. Sueldo, Bono, Vales de despensa, Regalo.'),
            'received_on' => $schema->string()->description('Fecha AAAA-MM-DD (hoy por defecto).'),
            'notes' => $schema->string(),
        ];
    }
}
