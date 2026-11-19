<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un paso de la preparación: etapa opcional ("Salsa"), texto y minutos para el temporizador. */
#[Fillable(['recipe_id', 'position', 'stage', 'body', 'minutes'])]
class RecipeStep extends Model
{
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'minutes' => 'integer',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
