<?php

use App\Http\Middleware\McpToken;
use App\Mcp\PriceTrackServer;
use Laravel\Mcp\Facades\Mcp;

// Servidor MCP: un asistente de IA consulta y usa la app con las herramientas de app/Mcp/Tools.
// Por HTTP en /mcp (MCP_ENABLED, MCP_TOKEN) y local por stdio: php artisan mcp:start pricetrack
if (config('services.mcp.enabled')) {
    Mcp::web('/mcp', PriceTrackServer::class)->middleware(McpToken::class);
}

Mcp::local('pricetrack', PriceTrackServer::class);
