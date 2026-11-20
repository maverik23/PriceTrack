<?php

namespace App\Support;

use App\Models\Payment;
use Illuminate\Support\Facades\Validator;

/**
 * Pagos únicos (gasolina, una comida fuera, una compra): concepto, monto, fecha, categoría y
 * notas. Lo usan la pantalla de Ingresos y pagos, la API de la app y el MCP. Los pagos de un
 * recurrente y los abonos a deudas tienen sus reglas en RecurringPaymentData y DebtData.
 */
class PaymentData
{
    /** @return array<string, mixed> los campos validados y normalizados */
    public static function validate(array $input): array
    {
        $data = Validator::validate($input, [
            'name' => ['required', 'string', 'max:120'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'category' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['name' => 'concepto', 'paid_on' => 'fecha', 'amount' => 'monto', 'category' => 'categoría']);
        $data['category'] = Payment::canonicalCategory($data['category'] ?? null);

        return $data;
    }

    /** Registra un pago único. */
    public static function create(array $input): Payment
    {
        return Payment::create(self::validate($input));
    }
}
