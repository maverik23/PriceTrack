<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Autocompletar artículos por código de barras (Open Food Facts, UPCitemdb)
    'barcode_lookup' => [
        'enabled' => (bool) env('BARCODE_LOOKUP', true),
        'user_agent' => env('BARCODE_LOOKUP_USER_AGENT', 'PriceTrack/1.0 (self-hosted)'),
        'timeout' => (int) env('BARCODE_LOOKUP_TIMEOUT', 6),
    ],

    // Consultar el precio de un artículo en la página de la tienda (App\Services\PriceFetcher)
    'price_fetch' => [
        // Las tiendas solo responden a navegadores; se usa la firma de uno común
        'user_agent' => env('PRICE_FETCH_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36'),
        'timeout' => (int) env('PRICE_FETCH_TIMEOUT', 15),
        // Rechazar enlaces cuyo dominio apunte a la red interna
        'resolve_hosts' => true,
        // Navegador real (contenedor "browser", docker/browser) para tiendas que bloquean a los programas
        'browser_url' => env('PRICE_FETCH_BROWSER_URL'),
        'browser_timeout' => (int) env('PRICE_FETCH_BROWSER_TIMEOUT', 90),
        'browser_hosts' => ['walmart.com.mx', 'sams.com.mx', 'soriana.com', 'amazon.com.mx', 'bodegaaurrera.com.mx'],
    ],

    // API REST para la app móvil (routes/api.php, App\Http\Controllers\Api), en /api/v1
    'api' => [
        // false quita /api/v1
        'enabled' => (bool) env('API_ENABLED', true),
        // Si se define, /api/v1 exige "Authorization: Bearer <token>"
        'token' => env('API_TOKEN'),
    ],

    // Servidor MCP para asistentes de IA (routes/ai.php, App\Mcp\PriceTrackServer)
    'mcp' => [
        // false quita la dirección /mcp (el servidor local por stdio sigue disponible)
        'enabled' => (bool) env('MCP_ENABLED', true),
        // Si se define, /mcp exige "Authorization: Bearer <token>"
        'token' => env('MCP_TOKEN'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
