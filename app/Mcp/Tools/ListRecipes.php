<?php

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\Tool;
use App\Models\Recipe;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Recetas guardadas, con porciones, tiempo y costo estimado (total y por porción, con el precio más barato de cada ingrediente). Se puede filtrar por texto o categoría.')]
#[IsReadOnly]
class ListRecipes extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'query' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:60'],
        ]);

        $recipes = Recipe::with('ingredients.product.prices')
            ->when(filled($data['query'] ?? null), fn ($q) => $q->where('name', 'like', '%'.trim($data['query']).'%'))
            ->when(filled($data['category'] ?? null), fn ($q) => $q->where('category', Recipe::canonicalCategory($data['category'])))
            ->orderBy('name')->get();

        return $this->json([
            'recipes' => $recipes->map(fn (Recipe $r) => Present::recipe($r))->all(),
            'categories' => Recipe::categories()->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Texto a buscar en el nombre.'),
            'category' => $schema->string()->description('Solo recetas de esta categoría.'),
        ];
    }
}
