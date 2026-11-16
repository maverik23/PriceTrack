<?php

namespace App\Services;

use App\Enums\Unit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Lee el precio actual de un artículo desde su página en la tienda.
 *
 * - Chedraui (VTEX): API pública del catálogo. Con código postal, el precio de
 *   esa zona sale de la simulación del carrito (cambia por región).
 * - Costco (SAP Commerce): API OCC del sitio "mexico"; el precio ya trae el cupón.
 * - Cualquier otra página: datos estructurados (JSON-LD Product → offers.price,
 *   metaetiquetas de precio) o el precio de Amazon. Home Depot da el precio de
 *   su tienda por defecto.
 *
 * Walmart y Sam's (PerimeterX), Soriana (Cloudflare) y Amazon bloquean las consultas
 * de programas. Si está configurado el navegador de PriceTrack (contenedor "browser",
 * docker/browser: Chromium real con ventana), esas páginas se abren con él; si no,
 * se informa como bloqueo para capturar el precio a mano.
 */
class PriceFetcher
{
    /**
     * Precio y, cuando la tienda los da, nombre, presentación, marca, código de barras
     * (EAN) y código propio de la tienda (sku).
     *
     * @return array{price: float, name: ?string, presentation: ?array{pack_qty: int, size: float, unit: string, bulk: bool}, brand: ?string, barcode: ?string, sku: ?string, source: string}
     *
     * @throws PriceFetchException
     */
    public function fetch(string $url, ?string $postalCode = null): array
    {
        $host = $this->guard($url);

        $result = match (true) {
            str_ends_with($host, 'chedraui.com.mx') => $this->chedraui($url, $postalCode),
            str_ends_with($host, 'costco.com.mx') => $this->costco($url),
            default => $this->page($url),
        } + ['brand' => null, 'barcode' => null, 'sku' => null];

        if (! ($result['price'] > 0)) {
            throw new PriceFetchException('La tienda no muestra precio (¿agotado?).');
        }

        return $result;
    }

    // ---------- Chedraui (VTEX) ----------

    private function chedraui(string $url, ?string $postalCode): array
    {
        // https://www.chedraui.com.mx/leche-entera-lala-...-3371188/p
        if (! preg_match('#/([^/]+)/p/?$#', (string) parse_url($url, PHP_URL_PATH), $m)) {
            throw new PriceFetchException('No es la página de un producto de Chedraui (la dirección termina en /p).');
        }

        $base = $this->origin($url);
        $product = $this->json($this->get("{$base}/api/catalog_system/pub/products/search/{$m[1]}/p"))[0] ?? null;
        $item = $product['items'][0] ?? null;
        $offer = $item['sellers'][0]['commertialOffer'] ?? null;
        if (! $item || ! $offer) {
            throw new PriceFetchException('Chedraui ya no tiene ese producto.');
        }

        $price = (float) $offer['Price'];
        if ($postalCode) {
            $price = $this->chedrauiRegionalPrice($base, (string) $item['itemId'], $postalCode) ?? $price;
        }

        // Fruta, verdura, carne…: se venden por kg y el precio es por 1 kg. Lo demás trae
        // "Contenido del empaque": "1 pieza con 1.8L", "6 piezas de 355 ml"
        $unit = Unit::tryFrom(strtolower((string) ($item['measurementUnit'] ?? '')));

        return [
            'price' => round($price, 2),
            'name' => $product['productName'] ?? null,
            'presentation' => $unit && $unit !== Unit::Item
                ? ['pack_qty' => 1, 'size' => 1.0, 'unit' => $unit->value, 'bulk' => true]
                : self::presentation($product['Contenido del empaque'][0] ?? null),
            'brand' => $product['brand'] ?? null,
            'barcode' => $item['ean'] ?? null,
            'sku' => isset($item['itemId']) ? (string) $item['itemId'] : null,
            'source' => 'Chedraui',
        ];
    }

    /** Precio en la zona del código postal: simulación del carrito con la tienda que surte esa región. */
    private function chedrauiRegionalPrice(string $base, string $skuId, string $postalCode): ?float
    {
        try {
            $regions = $this->json($this->get("{$base}/api/checkout/pub/regions", ['country' => 'MEX', 'postalCode' => $postalCode]));
            $simulation = $this->json($this->client()->asJson()->post("{$base}/api/checkout/pub/orderForms/simulation?sc=1", [
                'items' => [['id' => $skuId, 'quantity' => 1, 'seller' => $regions[0]['sellers'][0]['id'] ?? '1']],
                'country' => 'MEX',
                'postalCode' => $postalCode,
            ]));
        } catch (PriceFetchException|ConnectionException) {
            return null; // sin región: se queda el precio general
        }

        $item = $simulation['items'][0] ?? null;
        if (! $item || empty($item['sellingPrice'])) {
            return null;
        }

        // En centavos y por "paso" de venta: 1 × 0.2 kg de plátano → se lleva a precio por kg
        return $item['sellingPrice'] / 100 / ((float) ($item['unitMultiplier'] ?? 1) ?: 1);
    }

    // ---------- Costco (SAP Commerce) ----------

    private function costco(string $url): array
    {
        // https://www.costco.com.mx/Categoria/.../p/694555
        if (! preg_match('#/p/(\w+)#', (string) parse_url($url, PHP_URL_PATH), $m)) {
            throw new PriceFetchException('No es la página de un producto de Costco (la dirección lleva /p/ y el número).');
        }

        $data = $this->json($this->get($this->origin($url)."/rest/v2/mexico/products/{$m[1]}", [
            'fields' => 'code,name,price(value),classifications(features(name,featureValues))', 'lang' => 'es_MX', 'curr' => 'MXN',
        ]));

        // Características: "Contenido Neto" ("8 pzas de 387 g") y "Marca". Costco no publica el EAN.
        $features = collect($data['classifications'] ?? [])->flatMap(fn ($c) => $c['features'] ?? []);
        $feature = fn (string $name) => $features->firstWhere('name', $name)['featureValues'][0]['value'] ?? null;

        return [
            'price' => round((float) ($data['price']['value'] ?? 0), 2),
            'name' => $data['name'] ?? null,
            'presentation' => self::presentation($feature('Contenido Neto')),
            'brand' => $feature('Marca'),
            'sku' => $data['code'] ?? $m[1],
            'source' => 'Costco',
        ];
    }

    /**
     * Piezas, contenido y unidad a partir de un texto de la tienda ("8 pzas de 387 g").
     *
     * @return array{pack_qty: int, size: float, unit: string, bulk: bool}|null
     */
    public static function presentation(?string $text): ?array
    {
        $parsed = BarcodeLookup::parseQuantity($text);

        return $parsed ? ['pack_qty' => $parsed['pack'], 'size' => $parsed['each'], 'unit' => $parsed['unit'], 'bulk' => false] : null;
    }

    // ---------- Cualquier otra página ----------

    private function page(string $url): array
    {
        $html = $this->html($url);
        $found = $this->jsonLd($html) ?? $this->metaPrice($html) ?? $this->amazonPrice($html);

        if (! $found) {
            throw new PriceFetchException('No encontré el precio en la página.');
        }

        return $found + ['presentation' => null, 'source' => (string) parse_url($url, PHP_URL_HOST)];
    }

    /** HTML de la página: directo, o con el navegador real si la tienda bloquea a los programas. */
    private function html(string $url): string
    {
        $browser = config('services.price_fetch.browser_url');
        if ($browser && Str::endsWith((string) parse_url($url, PHP_URL_HOST), config('services.price_fetch.browser_hosts'))) {
            return $this->viaBrowser($url);
        }

        try {
            return $this->get($url)->body();
        } catch (StoreBlockedException $e) {
            return $browser ? $this->viaBrowser($url) : throw $e;
        }
    }

    /** Abre la página en el navegador de PriceTrack y devuelve el HTML ya cargado. */
    private function viaBrowser(string $url): string
    {
        try {
            $response = Http::timeout((int) config('services.price_fetch.browser_timeout'))
                ->get(rtrim((string) config('services.price_fetch.browser_url'), '/').'/html', ['url' => $url]);
        } catch (ConnectionException) {
            throw new PriceFetchException('No responde el navegador de PriceTrack (contenedor «browser»).');
        }

        if (! $response->successful()) {
            throw new PriceFetchException('El navegador no pudo abrir la página: '.Str::limit(trim($response->body()), 120));
        }
        if (str_contains(rawurldecode((string) $response->header('X-Final-Url')), '/blocked') || $this->blockedBody($response->body())) {
            throw new StoreBlockedException('Bloqueado por la tienda aun con el navegador; intenta más tarde.');
        }

        return $response->body();
    }

    /** schema.org Product → offers.price (o lowPrice), en cualquier bloque JSON-LD. */
    private function jsonLd(string $html): ?array
    {
        preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $blocks);

        foreach ($blocks[1] as $block) {
            $data = json_decode(trim($block), true);
            $nodes = is_array($data) ? (array_is_list($data) ? $data : ($data['@graph'] ?? [$data])) : [];

            foreach ($nodes as $node) {
                if (! is_array($node) || ! in_array('Product', (array) ($node['@type'] ?? []), true)) {
                    continue;
                }
                $offers = $node['offers'] ?? [];
                $offer = array_is_list($offers) ? ($offers[0] ?? []) : $offers;
                $price = $this->number($offer['price'] ?? $offer['lowPrice'] ?? null);
                if ($price) {
                    $brand = $node['brand'] ?? null;
                    $gtin = $node['gtin13'] ?? $node['gtin'] ?? $node['gtin12'] ?? $node['gtin14'] ?? $node['gtin8'] ?? null;

                    return [
                        'price' => $price,
                        'name' => is_string($node['name'] ?? null) ? $node['name'] : $this->title($html),
                        'brand' => is_array($brand) ? ($brand['name'] ?? null) : $brand,
                        'barcode' => is_scalar($gtin) ? (string) $gtin : null,
                        'sku' => is_scalar($node['sku'] ?? null) ? (string) $node['sku'] : null,
                    ];
                }
            }
        }

        return null;
    }

    private function metaPrice(string $html): ?array
    {
        $patterns = [
            '#<meta[^>]+(?:property|name)=["\'](?:product|og):price:amount["\'][^>]+content=["\']([^"\']+)#i',
            '#<meta[^>]+itemprop=["\']price["\'][^>]+content=["\']([^"\']+)#i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m) && ($price = $this->number($m[1]))) {
                return ['price' => $price, 'name' => $this->title($html)];
            }
        }

        return null;
    }

    /** Amazon no publica JSON-LD: el precio va en el bloque corePrice (span .a-offscreen). */
    private function amazonPrice(string $html): ?array
    {
        $start = strpos($html, 'corePrice');
        if ($start !== false && preg_match('#class="a-offscreen">\s*([^<]+)<#', substr($html, $start, 20000), $m) && ($price = $this->number($m[1]))) {
            $name = preg_match('#id="productTitle"[^>]*>\s*([^<]+)<#', $html, $t) ? trim(html_entity_decode($t[1])) : $this->title($html);

            return ['price' => $price, 'name' => $name];
        }

        return null;
    }

    // ---------- HTTP ----------

    /** Solo páginas públicas por https: nada de IPs, localhost ni redes internas. */
    private function guard(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $local = $host === '' || ! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) || str_ends_with($host, '.local') || str_ends_with($host, '.localhost');

        if (($parts['scheme'] ?? '') !== 'https' || $local) {
            throw new PriceFetchException('El enlace debe ser una página https de la tienda.');
        }

        if (config('services.price_fetch.resolve_hosts')) {
            foreach (gethostbynamel($host) ?: [] as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new PriceFetchException('El enlace apunta a una dirección interna.');
                }
            }
        }

        return $host;
    }

    private function client(): PendingRequest
    {
        return Http::withUserAgent((string) config('services.price_fetch.user_agent'))
            ->withHeaders(['Accept-Language' => 'es-MX,es;q=0.9'])
            ->withOptions(['allow_redirects' => ['max' => 5, 'protocols' => ['https']]])
            ->connectTimeout(5)
            ->timeout((int) config('services.price_fetch.timeout'));
    }

    private function get(string $url, array $query = []): Response
    {
        try {
            $response = $this->client()->get($url, $query);
        } catch (ConnectionException) {
            throw new PriceFetchException('No se pudo conectar con la tienda.');
        }

        if ($this->blocked($response)) {
            throw new StoreBlockedException('Bloqueado por la tienda (anti-bots).');
        }
        if ($response->status() === 404) {
            throw new PriceFetchException('La página ya no existe (404).');
        }
        if (! $response->successful()) {
            throw new PriceFetchException("La tienda respondió con error {$response->status()}.");
        }

        return $response;
    }

    /**
     * PerimeterX (Walmart, Sam's) responde 412 o manda a /blocked con "Mantén presionado el botón",
     * Cloudflare (Soriana) 403 y Amazon 503 o una página de "Continuar a Compras" (captcha).
     */
    private function blocked(Response $response): bool
    {
        return in_array($response->status(), [403, 412, 429, 503], true) || $this->blockedBody($response->body());
    }

    private function blockedBody(string $html): bool
    {
        return Str::contains(substr($html, 0, 30000), ['validateCaptcha', 'px-captcha', '"appId":"PX', 'cf-error-details']);
    }

    private function json(Response $response): array
    {
        $data = $response->json();
        if (! is_array($data)) {
            throw new PriceFetchException('La tienda respondió algo inesperado.');
        }

        return $data;
    }

    private function origin(string $url): string
    {
        return 'https://'.parse_url($url, PHP_URL_HOST);
    }

    private function title(string $html): ?string
    {
        return preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]))) : null;
    }

    /** "4149.0", "$1,234.56", 4149 → float; null si no es un precio. */
    private function number(mixed $value): ?float
    {
        $clean = str_replace([',', '$', ' ', 'MXN'], '', trim((string) $value));

        return is_numeric($clean) && (float) $clean > 0 ? round((float) $clean, 2) : null;
    }
}
