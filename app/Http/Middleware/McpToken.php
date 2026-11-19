<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Si hay MCP_TOKEN, el servidor MCP solo responde a quien lo manda como Bearer. */
class McpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('services.mcp.token');

        if ($token !== '' && ! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32001, 'message' => 'Token inválido o faltante (Authorization: Bearer …).'],
            ], 401);
        }

        return $next($request);
    }
}
