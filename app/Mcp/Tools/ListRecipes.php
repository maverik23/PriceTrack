<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Recipe;
use App\Models\Tag;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Recetas guardadas, con porciones, rendimiento, tiempo, calificación (1 a 5), etiquetas y costo estimado (total y por porción, con el precio más barato de cada ingrediente; las sub-recetas se suman). Se puede filtrar por texto, categoría, etiqueta, calificación mínima o por lo que hay en la despensa (`pantry`: `ready` = se puede hacer con lo que hay, `almost` = le faltan 1 o 2 cosas; cada receta trae `pantry_check` con lo que falta). Lo escrito sin artículo (sal, agua) no se revisa (`unchecked`).')]
#[IsReadOnly]
class ListRecipes extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'query' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:60'],
            'tag' => ['nullable', 'string', 'max:60'],
            'min_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'pantry' => ['nullable', 'in:ready,almost'],
        ]);

        $recipes = Recipe::withTree()->with('tags')
            ->when(filled($data['query'] ?? null), fn ($q) => $q->where('name', 'like', '%'.trim($data['query']).'%'))
            ->when(filled($data['category'] ?? null), fn ($q) => $q->where('category', Recipe::canonicalCategory($data['category'])))
            ->when(filled($data['tag'] ?? null), fn ($q) => $q->whereHas('tags', fn ($t) => $t->where('name', trim($data['tag']))))
            ->when(filled($data['min_rating'] ?? null), fn ($q) => $q->where('rating', '>=', $data['min_rating']))
            ->orderBy('name')->get();
        $checks = Recipe::pantryChecks($recipes);
        if (filled($data['pantry'] ?? null)) {
            $recipes = Recipe::filterByPantry($recipes, $checks, $data['pantry']);
        }

        return $this->json([
            'recipes' => $recipes->map(fn (Recipe $r) => Present::recipe($r) + ['pantry_check' => $checks[$r->id]])->all(),
            'categories' => Recipe::categories()->all(),
            'tags' => Tag::names()->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Texto a buscar en el nombre.'),
            'category' => $schema->string()->description('Solo recetas de esta categoría.'),
            'tag' => $schema->string()->description('Solo recetas con esta etiqueta.'),
            'min_rating' => $schema->integer()->description('Solo recetas calificadas con al menos esto (1 a 5).'),
            'pantry' => $schema->string()->enum(['ready', 'almost'])->description('ready = se pueden hacer con la despensa; almost = les faltan 1 o 2 cosas.'),
        ];
    }
}
