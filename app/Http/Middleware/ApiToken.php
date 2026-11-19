<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API de la app móvil (/api/v1): siempre responde JSON y, si hay API_TOKEN, solo a quien
 * lo manda como Bearer.
 */
class ApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // Los controladores compartidos con la web deciden con expectsJson()
        $request->headers->set('Accept', 'application/json');

        $token = (string) config('services.api.token');

        if ($token !== '' && ! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Token inválido o faltante (Authorization: Bearer …).'], 401);
        }

        return $next($request);
    }
}
