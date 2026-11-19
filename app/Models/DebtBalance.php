<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['debt_id', 'balance_on', 'balance'])]
class DebtBalance extends Model
{
    protected function casts(): array
    {
        return ['balance_on' => 'date', 'balance' => 'float'];
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }
}
