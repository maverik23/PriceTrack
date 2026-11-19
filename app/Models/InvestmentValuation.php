<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['investment_account_id', 'valued_on', 'value', 'exchange_rate'])]
class InvestmentValuation extends Model
{
    protected function casts(): array
    {
        return ['valued_on' => 'date', 'value' => 'float', 'exchange_rate' => 'float'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InvestmentAccount::class, 'investment_account_id');
    }

    public function valueMxn(): float
    {
        return round($this->value * ($this->exchange_rate ?: 1), 2);
    }
}
