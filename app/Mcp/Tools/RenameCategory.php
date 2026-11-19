<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Support\Categories;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Renombra una categoría (o una marca) en todos sus registros; también sirve para corregir cómo se escribe ("Lacteos" → "Lácteos"). Al guardar, la app reusa la escritura que ya existe sin importar mayúsculas ni acentos, así que escribir "Lácteos" en un artículo no cambia "Lacteos": hay que renombrarla aquí. Si el nombre nuevo ya existe, se juntan. Sin `from` devuelve las que hay y en cuántos registros.')]
class RenameCategory extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(Categories::KINDS))],
            'from' => ['nullable', 'string', 'max:100'],
            'to' => ['required_with:from', 'nullable', 'string', 'max:60'],
        ], [], ['kind' => 'tipo', 'from' => 'categoría actual', 'to' => 'nombre nuevo']);

        if (! filled($data['from'] ?? null)) {
            return $this->json(['kind' => $data['kind'], 'values' => Categories::values($data['kind'])->all()]);
        }

        $result = Categories::rename($data['kind'], $data['from'], $data['to']);
        if (! $result) {
            throw ValidationException::withMessages(['from' => 'No existe «'.$data['from'].'». Las que hay: '.Categories::values($data['kind'])->keys()->join(', ').'.']);
        }

        return $this->json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(array_keys(Categories::KINDS))->description('product_category, product_brand, recipe_category, asset_category, payment_category o income_category.')->required(),
            'from' => $schema->string()->description('La categoría como está ahora (no importan mayúsculas ni acentos). Sin ella, lista las que hay.'),
            'to' => $schema->string()->description('Cómo debe quedar.'),
        ];
    }
}
