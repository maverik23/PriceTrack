<?php

namespace App\Support;

use App\Models\Debt;
use App\Models\DebtBalance;
use App\Models\Payment;
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

    /** El saldo según el estado de cuenta (uno por día: corrige el de ese día). */
    public static function saveBalance(Debt $debt, array $input): DebtBalance
    {
        $data = Validator::validate($input, [
            'balance_on' => ['required', 'date', 'before_or_equal:today'],
            'balance' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
        ], [], ['balance_on' => 'fecha', 'balance' => 'saldo']);

        $existing = $debt->balances()->whereDate('balance_on', $data['balance_on'])->first();

        return $existing ? tap($existing)->update($data) : $debt->balances()->create($data);
    }

    /** Un pago suelto o un abono a capital (los mensuales se registran desde sus pagos recurrentes). */
    public static function savePayment(Debt $debt, array $input): Payment
    {
        $data = Validator::validate($input, [
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['paid_on' => 'fecha', 'amount' => 'monto']);

        return $debt->payments()->create($data);
    }
}
