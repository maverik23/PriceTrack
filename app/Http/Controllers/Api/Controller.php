<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller as BaseController;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Base de la API de la app móvil: paginación y respuestas con la misma forma. */
abstract class Controller extends BaseController
{
    public const MAX_PER_PAGE = 200;

    /** `?per_page=` (1 a 200; la app pide páginas grandes para guardar la copia sin conexión). */
    protected function perPage(Request $request, int $default = 50): int
    {
        $asked = $request->integer('per_page');

        return $asked > 0 ? min($asked, self::MAX_PER_PAGE) : $default;
    }

    /**
     * @param  callable(mixed): array<string, mixed>  $map
     * @param  array<string, mixed>  $extra
     */
    protected function page(LengthAwarePaginator $paginator, callable $map, array $extra = []): JsonResponse
    {
        return response()->json([
            'data' => collect($paginator->items())->map($map)->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ] + $extra);
    }

    /**
     * Unidades de repetición (Task::REPEAT_UNITS, HasSchedule::scheduleUnits()) como
     * `{clave: {one, many}}`: "mes" / "meses".
     *
     * @param  array<string, array{0: string, 1: string}>  $units
     * @return array<string, array{one: string, many: string}>
     */
    protected static function units(array $units): array
    {
        return array_map(fn (array $names) => ['one' => $names[0], 'many' => $names[1]], $units);
    }
}
