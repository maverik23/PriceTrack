<?php

namespace App\Support;

use App\Models\Income;
use App\Models\IncomeSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Ingresos: los eventuales, los recurrentes y marcar recibido el periodo que toca. Lo usan la
 * pantalla (IncomeController) y la API de la app.
 */
class IncomeData
{
    /** Un ingreso eventual (bono, venta, regalo…). @return array<string, mixed> */
    public static function validate(array $input): array
    {
        $data = Validator::validate($input, [
            'name' => ['required', 'string', 'max:120'],
            'received_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'kind' => ['required', Rule::in(array_keys(Income::KINDS))],
            'category' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['name' => 'concepto', 'received_on' => 'fecha', 'amount' => 'monto', 'kind' => 'tipo', 'category' => 'categoría']);
        $data['category'] = Income::canonicalCategory($data['category'] ?? null);

        return $data;
    }

    /** Un ingreso recurrente (sueldo, renta, vales). @return array<string, mixed> */
    public static function validateSource(array $input): array
    {
        $data = Validator::validate($input, [
            'name' => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'kind' => ['required', Rule::in(array_keys(Income::KINDS))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'every' => ['required', 'integer', 'min:1', 'max:999'],
            'every_unit' => ['required', Rule::in(array_keys(IncomeSource::scheduleUnits(true)))],
            'next_on' => ['required', 'date'],
            'active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], [
            'name' => 'concepto', 'category' => 'categoría', 'kind' => 'tipo', 'amount' => 'monto',
            'every' => 'cada cuánto', 'every_unit' => 'unidad', 'next_on' => 'próxima fecha',
        ]);
        $data['category'] = Income::canonicalCategory($data['category'] ?? null);
        $data['active'] = filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN);

        return $data;
    }

    /** El ingreso recurrente como entrada (para cambiar solo algunos campos). @return array<string, mixed> */
    public static function sourceInput(IncomeSource $source): array
    {
        return $source->only(['name', 'category', 'kind', 'amount', 'every', 'every_unit', 'active', 'notes'])
            + ['next_on' => $source->next_on?->toDateString()];
    }

    /** Marca recibido el periodo que toca, con el monto de siempre o el que se indique. */
    public static function receive(IncomeSource $source, array $input): Income
    {
        $data = Validator::validate($input, [
            'received_on' => ['nullable', 'date', 'before_or_equal:today'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:99999999999'],
        ], [], ['received_on' => 'fecha', 'amount' => 'monto']);

        return $source->markReceived(
            isset($data['received_on']) ? CarbonImmutable::parse($data['received_on']) : null,
            isset($data['amount']) ? (float) $data['amount'] : null,
        );
    }
}
