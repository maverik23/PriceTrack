<?php

namespace App\Support;

use App\Models\Debt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Campos de una deuda (DebtController y el MCP save-debt). */
class DebtData
{
    public static function validate(array $input): array
    {
        return Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'lender' => ['nullable', 'string', 'max:80'],
            'type' => ['required', Rule::in(array_keys(Debt::TYPES))],
            'original_amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'annual_rate' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'started_on' => ['nullable', 'date', 'before_or_equal:today'],
            'paid_off_on' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], [
            'lender' => 'acreedor', 'type' => 'tipo', 'original_amount' => 'monto original',
            'annual_rate' => 'tasa anual', 'started_on' => 'fecha de inicio', 'paid_off_on' => 'fecha en que se liquidó',
        ])->validate();
    }

    public static function toInput(Debt $debt): array
    {
        return $debt->only(['name', 'lender', 'type', 'original_amount', 'annual_rate', 'notes'])
            + ['started_on' => $debt->started_on?->toDateString(), 'paid_off_on' => $debt->paid_off_on?->toDateString()];
    }
}
