<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Nota de un día del menú ("Mealprep: cocer arroz y pollo para la semana"). */
#[Fillable(['planned_on', 'body'])]
class MealDayNote extends Model
{
    protected function casts(): array
    {
        return ['planned_on' => 'date'];
    }

    /** Guarda o borra (texto vacío) la nota de ese día. */
    public static function put(string $date, ?string $body): ?self
    {
        $body = trim((string) $body);
        $note = static::whereDate('planned_on', $date)->first();
        if ($body === '') {
            $note?->delete();

            return null;
        }

        $note ??= new static(['planned_on' => $date]);
        $note->fill(['body' => $body])->save();

        return $note;
    }
}
