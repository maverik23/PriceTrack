<?php

namespace App\Support;

use App\Models\InvestmentAccount;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Campos de una cuenta de inversión (InvestmentController y el MCP save-investment-account). */
class InvestmentAccountData
{
    public static function validate(array $input, ?InvestmentAccount $account = null): array
    {
        $input['currency'] = strtoupper(trim((string) ($input['currency'] ?? 'MXN'))) ?: 'MXN';
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'institution' => ['nullable', 'string', 'max:80'],
            'type' => ['required', Rule::in(array_keys(InvestmentAccount::TYPES))],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'notes' => ['nullable', 'string', 'max:255'],
            'closed_on' => ['nullable', 'date', 'before_or_equal:today'],
        ], [], ['institution' => 'institución', 'type' => 'tipo', 'currency' => 'moneda', 'closed_on' => 'fecha de cierre'])->validate();

        // Cambiar la moneda con registros dejaría los tipos de cambio sin sentido
        if ($account && $account->currency !== $data['currency']
            && ($account->transactions()->exists() || $account->valuations()->exists())) {
            throw ValidationException::withMessages(['currency' => 'La cuenta ya tiene registros en '.$account->currency.'; crea otra cuenta para otra moneda.']);
        }

        return $data;
    }

    public static function toInput(InvestmentAccount $account): array
    {
        return $account->only(['name', 'institution', 'type', 'currency', 'notes']) + ['closed_on' => $account->closed_on?->toDateString()];
    }
}
