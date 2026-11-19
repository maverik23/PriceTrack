<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['planned_on', 'meal', 'recipe_id', 'title', 'servings'])]
class MealPlanEntry extends Model
{
    public const MEALS = [
        'breakfast' => 'Desayuno',
        'lunch' => 'Comida',
        'snack' => 'Merienda',
        'dinner' => 'Cena',
        'other' => 'Otro',
    ];

    protected function casts(): array
    {
        return [
            'planned_on' => 'date',
            'servings' => 'integer',
        ];
    }

    /** Relaciones para costear lo planeado (receta con sus sub-recetas). */
    public static function tree(): array
    {
        return array_map(fn (string $relation) => 'recipe.'.$relation, Recipe::TREE);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function label(): string
    {
        return $this->recipe?->name ?? (string) $this->title;
    }

    public function mealLabel(): string
    {
        return self::MEALS[$this->meal] ?? $this->meal;
    }

    /** Porciones planeadas (las de la receta si no se indicó otra cosa). */
    public function plannedServings(): ?int
    {
        return $this->servings ?? $this->recipe?->servings;
    }

    public function cost(): ?float
    {
        return $this->recipe?->cost($this->plannedServings());
    }
}
