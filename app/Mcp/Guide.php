<?php

namespace App\Mcp;

use App\Mcp\Tool as BaseTool;

/**
 * Qué tiene el servidor MCP y cómo se usa: sale de PriceTrackServer::SECTIONS y de la
 * descripción y el esquema de cada herramienta, así que no se desactualiza. La usan la
 * herramienta get-help y la pantalla "Asistente (MCP)".
 */
class Guide
{
    /** Qué se puede hacer en cada sección, en palabras. */
    public const SUMMARIES = [
        'general' => 'Esta guía, un resumen de todo lo de la casa y renombrar categorías o marcas.',
        'super' => 'Artículos (alta, corrección y su versión y dirección en cada tienda), tiendas y su sucursal, precios (también en línea), listas de compra con su ticket, reportes de gasto, y borrar precios, listas, artículos o recetas.',
        'cocina' => 'Recetas (y cuáles se pueden hacer con la despensa), menú de la semana, despensa con su valor (y mandar lo que se acabó a la lista) y mandar ingredientes a la lista.',
        'hogar' => 'Tareas y sus listas; bienes (alta, valor, lecturas del medidor, servicios, planes de mantenimiento y venta o baja).',
        'finanzas' => 'Pagos recurrentes (con lecturas de medidor), ingresos (dinero o en especie), cuentas de inversión, deudas y patrimonio; con corrección y borrado de cada registro.',
    ];

    /**
     * @return array<string, array{title: string, summary: string, tools: list<array{name: string, description: string, read_only: bool, destructive: bool, params: list<array{name: string, required: bool, description: ?string}>}>}>
     */
    public static function sections(?string $only = null): array
    {
        $out = [];
        foreach (PriceTrackServer::SECTIONS as $key => [$title, $classes]) {
            if ($only && $only !== $key) {
                continue;
            }
            $out[$key] = [
                'title' => $title,
                'summary' => self::SUMMARIES[$key] ?? '',
                'tools' => array_map(fn (string $class) => self::tool(app($class)), $classes),
            ];
        }

        return $out;
    }

    /** Cuántas herramientas hay y cuántas consultan (las demás escriben). @return array{total: int, read: int, write: int} */
    public static function counts(): array
    {
        $tools = collect(self::sections())->flatMap(fn ($s) => $s['tools']);

        return ['total' => $tools->count(), 'read' => $tools->where('read_only', true)->count(), 'write' => $tools->where('read_only', false)->count()];
    }

    /** La guía completa en Markdown (lo que responde get-help). */
    public static function markdown(?string $only = null): string
    {
        $counts = self::counts();
        $lines = [
            '# PriceTrack por MCP',
            '',
            "Hay {$counts['total']} herramientas: {$counts['read']} consultan y {$counts['write']} escriben (crean, registran o cambian). Las de escribir usan los mismos cálculos que la app.",
            '',
            '## Cómo se usa',
            '- Primero consulta para obtener los `id` (por ejemplo `get-finances`, `search-products`, `list-tasks`) y luego úsalos en las que escriben.',
            '- Fechas en AAAA-MM-DD, montos en pesos (MXN). Si falta un dato obligatorio la herramienta responde con el error en español.',
            '- Confirma con la persona antes de borrar algo (las herramientas que borran lo indican).',
            '',
            '## Cómo se conecta',
            '- Por HTTP: `POST '.url('/mcp').'`'.(config('services.mcp.token') ? ' con el encabezado `Authorization: Bearer <MCP_TOKEN>`' : ' (sin token; se puede exigir con MCP_TOKEN)').'.',
            '- En la misma máquina, por stdio: `php artisan mcp:start pricetrack`.',
            '- Si una herramienta nueva no aparece, desconecta y vuelve a conectar el cliente para que pida otra vez la lista.',
        ];

        foreach (self::sections($only) as $section) {
            $lines[] = '';
            $lines[] = "## {$section['title']}";
            $lines[] = $section['summary'];
            foreach ($section['tools'] as $tool) {
                $kind = $tool['destructive'] ? 'borra' : ($tool['read_only'] ? 'consulta' : 'escribe');
                $params = collect($tool['params'])->map(fn ($p) => '`'.$p['name'].'`'.($p['required'] ? '*' : ''))->join(', ');
                $lines[] = "- **`{$tool['name']}`** ({$kind}): {$tool['description']}".($params ? " Parámetros: {$params}." : '');
            }
        }
        $lines[] = '';
        $lines[] = '`*` = obligatorio.';

        return implode("\n", $lines);
    }

    private static function tool(BaseTool $tool): array
    {
        $data = $tool->toArray();
        $schema = $data['inputSchema'] ?? [];
        $required = $schema['required'] ?? [];
        $params = collect((array) ($schema['properties'] ?? []))->map(fn ($p, $name) => [
            'name' => $name,
            'required' => in_array($name, $required, true),
            'description' => is_array($p) ? ($p['description'] ?? null) : null,
        ])->values()->all();

        return [
            'name' => $data['name'],
            'description' => $data['description'],
            'read_only' => (bool) ($data['annotations']['readOnlyHint'] ?? false),
            'destructive' => (bool) ($data['annotations']['destructiveHint'] ?? false),
            'params' => $params,
        ];
    }
}
