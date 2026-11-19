<?php

namespace App\Mcp;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as BaseTool;

/** Base de las herramientas: buscar por id con un error legible y responder en JSON. */
abstract class Tool extends BaseTool
{
    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @param  array<int, string>  $with
     * @return T
     */
    protected function find(string $model, mixed $id, string $what, array $with = []): Model
    {
        $found = is_numeric($id) ? $model::with($with)->find((int) $id) : null;

        if (! $found) {
            throw ValidationException::withMessages(['id' => "No existe {$what} con id ".json_encode($id).'.']);
        }

        return $found;
    }

    /** Fecha de un argumento (AAAA-MM-DD) o la de respaldo. */
    protected function date(mixed $value, ?CarbonImmutable $default = null): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse($value)->startOfDay() : $default;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $notices  avisos para el asistente (p. ej. se reusó una categoría existente)
     */
    protected function json(array $data, array $notices = []): Response
    {
        return Response::json($notices ? $data + ['notices' => $notices] : $data);
    }
}
