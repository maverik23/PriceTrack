<?php

use App\Services\BranchRequiredException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Falta el código postal o la sucursal de una tienda con precio por sucursal: la
        // pantalla abre la ventana para elegirla (resources/js/online-prices.js) y reintenta
        $exceptions->render(fn (BranchRequiredException $e, Request $request) => $request->expectsJson()
            ? response()->json(['message' => $e->getMessage(), 'needs_branch' => $e->info], 409)
            : redirect()->route('providers.index')->withErrors(['postal_code' => $e->getMessage()]));
    })->create();
