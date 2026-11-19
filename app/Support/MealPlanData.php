<?php

namespace App\Support;

use App\Models\MealPlanEntry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Una comida del menú: receta (con porciones) o texto libre. La usan MenuController y la API. */
class MealPlanData
{
    /** @return array<string, mixed> */
    public static function validate(array $input): array
    {
        $data = Validator::validate($input, [
            'planned_on' => ['required', 'date'],
            'meal' => ['required', Rule::in(array_keys(MealPlanEntry::MEALS))],
            'recipe_id' => ['nullable', 'required_without:title', 'exists:recipes,id'],
            'title' => ['nullable', 'required_without:recipe_id', 'string', 'max:150'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:999'],
        ], [
            'recipe_id.required_without' => 'Elige una receta o escribe qué se va a comer.',
            'title.required_without' => 'Elige una receta o escribe qué se va a comer.',
        ], ['planned_on' => 'día', 'meal' => 'comida', 'servings' => 'porciones']);

        if (! empty($data['recipe_id'])) {
            $data['title'] = null;
        } else {
            $data['servings'] = null;
        }

        return $data;
    }
}
