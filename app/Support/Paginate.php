<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;

/**
 * Cuántos renglones por página (10, 20, 50 o 100) en las pantallas con listas.
 *
 * `?per_page=` lo cambia y se recuerda por pantalla (cookie de un año), así que buscar o
 * filtrar después conserva lo elegido. El selector lo pinta la plantilla de paginación
 * (`vendor/pagination/tailwind.blade.php`).
 */
class Paginate
{
    public const OPTIONS = [10, 20, 50, 100];

    public const DEFAULT = 20;

    public static function perPage(Request $request, int $default = self::DEFAULT): int
    {
        $cookie = 'per_page_'.preg_replace('/\W+/', '_', $request->route()?->getName() ?? $request->path());

        $asked = (int) $request->query('per_page');
        if (in_array($asked, self::OPTIONS, true)) {
            Cookie::queue($cookie, (string) $asked, 60 * 24 * 365);

            return $asked;
        }

        $saved = (int) $request->cookie($cookie);

        return in_array($saved, self::OPTIONS, true) ? $saved : $default;
    }

    /** Pagina una colección ya filtrada en PHP (recetas con lo de la despensa, cuentas con su valor). */
    public static function collection(Collection $items, Request $request, ?int $perPage = null): LengthAwarePaginator
    {
        $perPage ??= self::perPage($request);
        $page = max(1, $request->integer('page', 1));
        $last = max(1, (int) ceil($items->count() / $perPage));
        $page = min($page, $last);

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }
}
