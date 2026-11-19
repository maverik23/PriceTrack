<?php

namespace App\Support;

use App\Models\InvestmentAccount;
use App\Models\InvestmentTransaction;
use App\Models\InvestmentValuation;
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

    /** Un movimiento (aportación, retiro, rendimiento o comisión); el tipo de cambio solo si no es MXN. */
    public static function saveTransaction(InvestmentAccount $account, array $input): InvestmentTransaction
    {
        $data = Validator::validate($input, [
            'occurred_on' => ['required', 'date', 'before_or_equal:today'],
            'kind' => ['required', Rule::in(array_keys(InvestmentTransaction::KINDS))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'exchange_rate' => self::rateRules($account),
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['occurred_on' => 'fecha', 'kind' => 'tipo de movimiento', 'amount' => 'monto', 'exchange_rate' => 'tipo de cambio']);
        $data['exchange_rate'] = $account->isForeign() ? $data['exchange_rate'] : 1;

        return $account->transactions()->create($data);
    }

    /** El valor de la cuenta en una fecha (uno por día: corrige el de ese día). */
    public static function saveValuation(InvestmentAccount $account, array $input): InvestmentValuation
    {
        $data = Validator::validate($input, [
            'valued_on' => ['required', 'date', 'before_or_equal:today'],
            'value' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'exchange_rate' => self::rateRules($account),
        ], [], ['valued_on' => 'fecha', 'value' => 'valor', 'exchange_rate' => 'tipo de cambio']);
        $data['exchange_rate'] = $account->isForeign() ? $data['exchange_rate'] : 1;

        $existing = $account->valuations()->whereDate('valued_on', $data['valued_on'])->first();

        return $existing ? tap($existing)->update($data) : $account->valuations()->create($data);
    }

    private static function rateRules(InvestmentAccount $account): array
    {
        return $account->isForeign() ? ['required', 'numeric', 'gt:0', 'max:1000000'] : ['nullable'];
    }
}
