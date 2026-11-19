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
}
