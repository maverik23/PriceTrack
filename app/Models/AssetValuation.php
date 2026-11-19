<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['asset_id', 'valued_on', 'value', 'notes'])]
class AssetValuation extends Model
{
    protected function casts(): array
    {
        return ['valued_on' => 'date', 'value' => 'float'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
