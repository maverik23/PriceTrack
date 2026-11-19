<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['recurring_payment_id', 'debt_id', 'paid_on', 'amount', 'due_on', 'notes'])]
class Payment extends Model
{
    protected function casts(): array
    {
        return ['paid_on' => 'date', 'due_on' => 'date', 'amount' => 'float'];
    }

    public function recurringPayment(): BelongsTo
    {
        return $this->belongsTo(RecurringPayment::class);
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }
}
