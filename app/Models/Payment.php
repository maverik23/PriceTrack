<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un pago hecho: de un pago recurrente (con la fecha que tocaba y, si lleva medidor, la lectura
 * final del periodo) o suelto a una deuda (abono).
 */
#[Fillable(['recurring_payment_id', 'debt_id', 'paid_on', 'amount', 'reading', 'due_on', 'notes'])]
class Payment extends Model
{
    protected function casts(): array
    {
        return ['paid_on' => 'date', 'due_on' => 'date', 'amount' => 'float', 'reading' => 'float'];
    }

    public function recurringPayment(): BelongsTo
    {
        return $this->belongsTo(RecurringPayment::class);
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    /** El pago anterior del mismo recurrente que tiene lectura (por la fecha que tocaba). */
    public function previousWithReading(): ?self
    {
        if (! $this->recurring_payment_id || $this->reading === null) {
            return null;
        }

        return static::where('recurring_payment_id', $this->recurring_payment_id)
            ->whereKeyNot($this->id)->whereNotNull('reading')
            ->where(fn ($q) => $q->where('due_on', '<', ($this->due_on ?? $this->paid_on)->toDateString())
                ->orWhere(fn ($q) => $q->where('due_on', ($this->due_on ?? $this->paid_on)->toDateString())->where('id', '<', $this->id)))
            ->orderByDesc('due_on')->orderByDesc('id')
            ->first();
    }

    /**
     * Consumo del periodo: esta lectura − la anterior (null sin lectura anterior o si el medidor
     * se reinició). `$previous` evita la consulta cuando ya se tiene.
     */
    public function consumption(?self $previous = null): ?float
    {
        $previous ??= $this->previousWithReading();
        if ($this->reading === null || $previous?->reading === null || $this->reading < $previous->reading) {
            return null;
        }

        return round($this->reading - $previous->reading, 3);
    }

    /** Lo que costó cada unidad consumida ($ por m³, por kWh). */
    public function unitPrice(?self $previous = null): ?float
    {
        $used = $this->consumption($previous);

        return $used > 0 ? round($this->amount / $used, 4) : null;
    }
}
