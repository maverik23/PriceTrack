<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\RecurringPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Pagos recurrentes: sus campos, pagar el recibo que toca (con monto y lectura) y corregir un
 * pago. Lo usan la pantalla (RecurringPaymentController) y la API de la app.
 */
class RecurringPaymentData
{
    /** @return array<string, mixed> los campos validados y normalizados */
    public static function validate(array $input): array
    {
        $data = Validator::validate($input, [
            'name' => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'meter_unit' => ['nullable', 'string', 'max:12'],
            'every' => ['required', 'integer', 'min:1', 'max:999'],
            'every_unit' => ['required', Rule::in(array_keys(RecurringPayment::scheduleUnits(true)))],
            'next_due_on' => ['required', 'date'],
            'debt_id' => ['nullable', 'exists:debts,id'],
            'active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], [
            'category' => 'categoría', 'amount' => 'monto', 'every' => 'cada cuánto', 'every_unit' => 'unidad',
            'next_due_on' => 'próximo pago', 'debt_id' => 'deuda', 'meter_unit' => 'unidad de la lectura',
        ]);

        $data['category'] = RecurringPayment::canonicalCategory($data['category'] ?? null);
        // El alta asume activo; el formulario de edición manda active=0 antes del checkbox
        $data['active'] = filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $data['meter_unit'] = trim((string) ($data['meter_unit'] ?? '')) ?: null;

        return $data;
    }

    /** El pago como entrada (para cambiar solo algunos campos). @return array<string, mixed> */
    public static function toInput(RecurringPayment $payment): array
    {
        return $payment->only(['name', 'category', 'amount', 'meter_unit', 'every', 'every_unit', 'debt_id', 'active', 'notes'])
            + ['next_due_on' => $payment->next_due_on?->toDateString()];
    }

    /**
     * Paga el recibo que toca: con el monto de este recibo (vacío = el de referencia) y, si
     * lleva medidor, la lectura final (la primera lectura de un pago sin unidad trae `meter_unit`).
     */
    public static function pay(RecurringPayment $recurring, array $input): Payment
    {
        $data = Validator::validate($input, [
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:99999999999'],
            'reading' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'meter_unit' => [Rule::requiredIf(fn () => filled($input['reading'] ?? null) && ! $recurring->meter_unit), 'nullable', 'string', 'max:12'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['paid_on' => 'fecha', 'amount' => 'monto', 'reading' => 'lectura', 'meter_unit' => 'unidad de la lectura']);

        if (isset($data['reading']) && ! $recurring->meter_unit) {
            $recurring->update(['meter_unit' => trim($data['meter_unit'])]);
        }

        return $recurring->markPaid(
            isset($data['paid_on']) ? CarbonImmutable::parse($data['paid_on']) : null,
            isset($data['amount']) ? (float) $data['amount'] : null,
            $data['notes'] ?? null,
            isset($data['reading']) ? (float) $data['reading'] : null,
        );
    }

    /**
     * Corrige un pago ya registrado (fecha, monto, lectura, notas y, si se manda, concepto y
     * categoría; en un pago único el concepto no puede quedar vacío).
     */
    public static function correct(Payment $payment, array $input): Payment
    {
        $data = Validator::validate($input, [
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'reading' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:255'],
            'name' => ['sometimes', $payment->isOneTime() ? 'required' : 'nullable', 'string', 'max:120'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
        ], [], ['paid_on' => 'fecha', 'amount' => 'monto', 'reading' => 'lectura', 'name' => 'concepto', 'category' => 'categoría']);
        if (array_key_exists('category', $data)) {
            $data['category'] = Payment::canonicalCategory($data['category']);
        }
        $payment->update($data);

        return $payment;
    }

    /** El pago como entrada de correct(). @return array<string, mixed> */
    public static function paymentInput(Payment $payment): array
    {
        return $payment->only(['amount', 'reading', 'notes', 'name', 'category']) + ['paid_on' => $payment->paid_on->toDateString()];
    }
}
