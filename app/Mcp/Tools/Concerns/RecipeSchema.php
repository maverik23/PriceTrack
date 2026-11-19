<?php

namespace App\Mcp\Tools\Concerns;

use App\Enums\IngredientUnit;
use App\Support\RecipeData;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Campos de una receta para create-recipe y update-recipe (las reglas están en App\Support\RecipeData). */
trait RecipeSchema
{
    /** @return array<string, mixed> */
    protected function recipeFields(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Nombre de la receta.'),
            'servings' => $schema->integer()->description('Porciones que rinde.'),
            'category' => $schema->string()->description('Categoría (Desayunos, Postres…); se reusa la escritura existente.'),
            'tags' => $schema->array()->items($schema->string())->description('Etiquetas ("Mealprep", "Alta en proteína"); se reusa la escritura de las existentes. Reemplaza las que tenga.'),
            'rating' => $schema->integer()->description('Calificación de 1 a 5 (null = sin calificar).'),
            'minutes' => $schema->integer()->description('Tiempo total en minutos.'),
            'yield_qty' => $schema->number()->description('Cuánto rinde en peso o volumen (1200), para usarla como sub-receta en g o ml.'),
            'yield_unit' => $schema->string()->enum(RecipeData::YIELD_UNITS)->description('Unidad de yield_qty.'),
            'source_url' => $schema->string()->description('Dirección de donde salió la receta.'),
            'notes' => $schema->string()->description('Notas largas; los saltos de línea se respetan.'),
            'ingredients' => $schema->array()->items($schema->object([
                'product_id' => $schema->integer()->description('Artículo del súper (search-products). Con él, quantity y unit son obligatorios: de ahí salen costo, lista de compras y macros.'),
                'subrecipe_id' => $schema->integer()->description('Otra receta usada como ingrediente (sub-receta, p. ej. una crema o un pan caseros): su costo, macros y compras se suman. Cantidad en porciones (unit "serving") o en g/ml si esa receta tiene yield.'),
                'name' => $schema->string()->description('Nombre, si es texto libre (ni artículo ni receta): "Sal".'),
                'quantity' => $schema->number(),
                'unit' => $schema->string()->enum(array_column(IngredientUnit::cases(), 'value')),
                'note' => $schema->string()->description('"picada", "al gusto"…'),
                'section' => $schema->string()->description('Sección del ingrediente ("Salsa", "Para servir"); los de la misma sección se agrupan.'),
            ]))->description('Ingredientes en orden. Reemplaza la lista completa.'),
            'steps' => $schema->array()->items($schema->object([
                'text' => $schema->string()->description('Lo que se hace en el paso.')->required(),
                'stage' => $schema->string()->description('Etapa opcional ("Salsa", "Albóndigas", "Montaje"); los pasos de una etapa se muestran juntos.'),
                'minutes' => $schema->integer()->description('Minutos del paso, para el temporizador del modo cocinar.'),
            ]))->description('Preparación paso a paso. Reemplaza los pasos.'),
            'instructions' => $schema->string()->description('Alternativa a steps: texto con un paso por línea. "# Etapa" empieza una etapa, "(15 min)" al final da los minutos y las líneas "Nota — …" van a las notas.'),
        ];
    }
}
