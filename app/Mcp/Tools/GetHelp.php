<?php

namespace App\Mcp\Tools;

use App\Mcp\Guide;
use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Guía de este servidor: qué se puede consultar y hacer en cada sección (súper, cocina, hogar, finanzas), con qué herramienta y sus parámetros, y cómo se conecta. Úsala si no sabes qué herramienta usar o si la persona pregunta qué puedes hacer.')]
#[IsReadOnly]
class GetHelp extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate(['section' => ['nullable', 'in:general,super,cocina,hogar,finanzas']], [], ['section' => 'sección']);

        return Response::text(Guide::markdown($data['section'] ?? null));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['section' => $schema->string()->enum(['general', 'super', 'cocina', 'hogar', 'finanzas'])->description('Solo una sección (todas por defecto).')];
    }
}
