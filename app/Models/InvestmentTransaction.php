<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['investment_account_id', 'occurred_on', 'kind', 'amount', 'exchange_rate', 'notes'])]
class InvestmentTransaction extends Model
{
    public const KINDS = [
        'deposit' => 'Aportación',
        'withdrawal' => 'Retiro',
        'income' => 'Dividendo o interés',
        'fee' => 'Comisión',
    ];

    protected function casts(): array
    {
        return ['occurred_on' => 'date', 'amount' => 'float', 'exchange_rate' => 'float'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InvestmentAccount::class, 'investment_account_id');
    }

    public function amountMxn(): float
    {
        return round($this->amount * ($this->exchange_rate ?: 1), 2);
    }

    /**
     * Lo que entra (+) o sale (−) de tu bolsillo hacia la cuenta. Dividendos,
     * intereses y comisiones se quedan dentro de la cuenta: ya están en su valor.
     */
    public function contribution(): float
    {
        return match ($this->kind) {
            'deposit' => $this->amountMxn(),
            'withdrawal' => -$this->amountMxn(),
            default => 0.0,
        };
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
