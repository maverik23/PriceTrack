<?php

namespace App\Services;

use App\Enums\Unit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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
     * `$branch` es la sucursal elegida en el proveedor (StoreBranches): en Chedraui, la tienda
     * donde se simula el carrito; en Walmart, Bodega Aurrera y Sam's, la tienda con la que el
     * navegador abre la página (y `store_id` dice de qué tienda salió el precio); en Costco
     * solo se anota.
     *
     * @return array{price: float, name: ?string, presentation: ?array{pack_qty: int, size: float, unit: string, bulk: bool}, brand: ?string, barcode: ?string, sku: ?string, store: ?string, store_id: ?string, image: ?string, source: string}
     *
     * @throws PriceFetchException
     */
    public function fetch(string $url, ?string $postalCode = null, ?string $branch = null, ?string $chain = null): array
    {
        $host = $this->guard($url);
        $chain ??= StoreBranches::chainFor($url);

        $result = match (true) {
            str_ends_with($host, 'chedraui.com.mx') => $this->chedraui($url, $postalCode, $branch),
            str_ends_with($host, 'costco.com.mx') => $this->costco($url, $branch),
            default => $this->page($url, $this->branchCookies($chain, $url, $branch, $postalCode)),
        } + ['brand' => null, 'barcode' => null, 'sku' => null, 'store' => null, 'store_id' => null, 'image' => null];

        if (! ($result['price'] > 0)) {
            throw new PriceFetchException('La tienda no muestra precio (¿agotado?).');
        }

        return $result;
    }

    /**
     * Dirección de la foto del artículo en la página de la tienda (sin consultar el precio).
     *
     * @throws PriceFetchException
     */
    public function image(string $url): ?string
    {
        $host = $this->guard($url);

        if (str_ends_with($host, 'chedraui.com.mx')) {
            if (! preg_match('#/([^/]+)/p/?$#', (string) parse_url($url, PHP_URL_PATH), $m)) {
                return null;
            }
            $product = $this->json($this->get($this->origin($url)."/api/catalog_system/pub/products/search/{$m[1]}/p"))[0] ?? null;

            return $product['items'][0]['images'][0]['imageUrl'] ?? null;
        }

        if (str_ends_with($host, 'costco.com.mx')) {
            if (! preg_match('#/p/(\w+)#', (string) parse_url($url, PHP_URL_PATH), $m)) {
                return null;
            }
            $data = $this->json($this->get($this->origin($url)."/rest/v2/mexico/products/{$m[1]}", ['fields' => 'code,images(url,format,imageType)', 'lang' => 'es_MX']));

            return $this->costcoImage($url, $data['images'] ?? []);
        }

        return $this->pageImage($this->html($url));
    }

    /**
     * Descarga una foto de internet (solo https de dominios públicos, hasta 8 MB, jpg/png/webp/gif/avif).
     *
     * @return array{contents: string, mime: string, extension: string}
     *
     * @throws PriceFetchException
     */
    public function download(string $url): array
    {
        $this->guard($url);
        try {
            // Sin AVIF en lo que se pide: Costco lo manda si se acepta y no todos lo muestran
            $response = $this->client()->withHeaders(['Accept' => 'image/webp,image/jpeg,image/png;q=0.9,*/*;q=0.5'])->get($url);
        } catch (ConnectionException) {
            throw new PriceFetchException('No se pudo descargar la foto.');
        }

        $mime = strtolower(trim(Str::before((string) $response->header('Content-Type'), ';')));
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif'][$mime] ?? null;
        if (! $response->successful() || ! $extension) {
            throw new PriceFetchException('La tienda no dio una foto que se pueda guardar.');
        }
        if (strlen($response->body()) > 8 * 1024 * 1024) {
            throw new PriceFetchException('La foto pesa demasiado.');
        }

        return ['contents' => $response->body(), 'mime' => $mime, 'extension' => $extension];
    }

    // ---------- Chedraui (VTEX) ----------

    private function chedraui(string $url, ?string $postalCode, ?string $store): array
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

        // El catálogo da un precio general que no es el de ninguna sucursal: con código
        // postal se usa el de tu tienda (la elegida o la más cercana que lo tenga)
        [$price, $seller] = $postalCode
            ? $this->chedrauiStorePrice($base, (string) $item['itemId'], $postalCode, $store)
            : [(float) $offer['Price'], null];

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
            'image' => $item['images'][0]['imageUrl'] ?? null,
            'store_id' => $seller,
            'store' => $seller ? Str::before($this->chedrauiStores((string) $postalCode)[$seller] ?? $seller, ' · ') : null,
            'source' => 'Chedraui',
        ];
    }

    /**
     * Precio en tu tienda Chedraui (cada sucursal tiene el suyo): simulación del carrito,
     * en una sola petición, en la tienda elegida o, en automático, en las tiendas cercanas
     * a tu código postal de la más cercana a la más lejana. Gana la primera que tenga
     * existencia ("cannotBeDelivered" solo dice que no envía a domicilio a tu CP).
     *
     * @return array{0: float, 1: string} precio por unidad de venta y vendedor (tienda)
     */
    private function chedrauiStorePrice(string $base, string $skuId, string $postalCode, ?string $store): array
    {
        try {
            $sellers = $store ? [$store] : (array_keys($this->chedrauiStores($postalCode)) ?: array_column(
                $this->json($this->get("{$base}/api/checkout/pub/regions", ['country' => 'MEX', 'postalCode' => $postalCode]))[0]['sellers'] ?? [],
                'id',
            ));
            $simulation = $this->json($this->client()->asJson()->post("{$base}/api/checkout/pub/orderForms/simulation?sc=1", [
                'items' => array_map(fn (string $seller) => ['id' => $skuId, 'quantity' => 1, 'seller' => $seller], $sellers ?: ['1']),
                'country' => 'MEX',
                'postalCode' => $postalCode,
            ]));
        } catch (ConnectionException) {
            throw new PriceFetchException('No se pudo consultar el precio de tu zona en Chedraui.');
        }

        $best = collect($simulation['items'] ?? [])
            ->filter(fn (array $item) => ! empty($item['sellingPrice']))
            ->sortBy(fn (array $item) => [($item['availability'] ?? '') === 'withoutStock' ? 1 : 0, $item['requestIndex'] ?? 0])
            ->first();

        if (! $best) {
            throw new PriceFetchException($store
                ? 'Tu tienda Chedraui no tiene este artículo.'
                : "Chedraui no tiene este artículo en tu zona (CP {$postalCode}).");
        }

        // En centavos y por "paso" de venta: 1 × 0.1 kg de ejote → se lleva a precio por kg
        return [$best['sellingPrice'] / 100 / ((float) ($best['unitMultiplier'] ?? 1) ?: 1), (string) $best['seller']];
    }

    /**
     * Tiendas Chedraui cerca de un código postal, para elegir la tuya.
     *
     * @return array<string, string> vendedor (chedrauimx0262) => "CHEDRAUI SELECTO … · 5 km"
     */
    public function chedrauiStores(string $postalCode): array
    {
        $key = "chedraui-stores:{$postalCode}";
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $data = $this->json($this->get('https://www.chedraui.com.mx/api/checkout/pub/pickup-points', ['postalCode' => $postalCode, 'countryCode' => 'MEX']));
        } catch (PriceFetchException) {
            return [];
        }

        $stores = collect($data['items'] ?? [])
            ->mapWithKeys(fn (array $item) => [
                Str::before((string) ($item['pickupPoint']['id'] ?? ''), '_') => trim((string) ($item['pickupPoint']['friendlyName'] ?? '')).' · '.round((float) ($item['distance'] ?? 0), 1).' km',
            ])
            ->filter(fn (string $name, string $seller) => str_starts_with($seller, 'chedrauimx'))
            ->all();

        if ($stores) {
            Cache::put($key, $stores, now()->addDay());
        }

        return $stores;
    }

    // ---------- Costco (SAP Commerce) ----------

    private function costco(string $url, ?string $warehouse = null): array
    {
        // https://www.costco.com.mx/Categoria/.../p/694555
        if (! preg_match('#/p/(\w+)#', (string) parse_url($url, PHP_URL_PATH), $m)) {
            throw new PriceFetchException('No es la página de un producto de Costco (la dirección lleva /p/ y el número).');
        }

        $data = $this->json($this->get($this->origin($url)."/rest/v2/mexico/products/{$m[1]}", [
            'fields' => 'code,name,price(value),images(url,format,imageType),classifications(features(name,featureValues))', 'lang' => 'es_MX', 'curr' => 'MXN',
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
            // Un solo precio en línea para todas las bodegas: la tuya solo se anota
            'store' => $warehouse && $warehouse !== StoreBranches::AUTO ? "Costco {$warehouse}" : null,
            'image' => $this->costcoImage($url, $data['images'] ?? []),
            'source' => 'Costco',
        ];
    }

    /** La foto del artículo en Costco (la de su ficha, "product"), con dirección completa. */
    private function costcoImage(string $url, array $images): ?string
    {
        $image = collect($images)->sortBy(fn (array $i) => match ($i['format'] ?? '') {
            'product' => 0, 'zoom' => 1, 'superZoom' => 2, default => 3,
        })->first();
        $path = $image['url'] ?? null;

        return $path ? (str_starts_with($path, 'http') ? $path : $this->origin($url).$path) : null;
    }

    /**
     * Bodegas Costco cerca de un código postal, de la más cercana a la más lejana.
     *
     * @return array<string, string> nombre de la bodega (Polanco) => "Polanco · 5 km"
     */
    public function costcoStores(string $postalCode): array
    {
        $key = "costco-stores:{$postalCode}";
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $data = $this->json($this->get('https://www.costco.com.mx/rest/v2/mexico/stores', [
                'query' => $postalCode, 'pageSize' => 20, 'lang' => 'es_MX',
                'fields' => 'stores(name,displayName,formattedDistance,address(town))',
            ]));
        } catch (PriceFetchException) {
            return [];
        }

        $stores = collect($data['stores'] ?? [])
            // "Oficinas Centrales" no es una bodega
            ->filter(fn (array $s) => filled($s['name'] ?? null) && ! str_contains(Str::lower($s['name']), 'oficina'))
            ->mapWithKeys(fn (array $s) => [
                (string) $s['name'] => collect([$s['displayName'] ?? $s['name'], $s['address']['town'] ?? null])->filter()->unique()->join(', ')
                    .(isset($s['formattedDistance']) ? ' · '.$s['formattedDistance'] : ''),
            ])
            ->all();

        if ($stores) {
            Cache::put($key, $stores, now()->addDay());
        }

        return $stores;
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

    /** @param  list<array{name: string, value: string, domain: string, path: string}>  $cookies */
    private function page(string $url, array $cookies = []): array
    {
        $html = $this->html($url, $cookies);
        $found = $this->jsonLd($html) ?? $this->metaPrice($html) ?? $this->amazonPrice($html);

        if (! $found) {
            throw new PriceFetchException('No encontré el precio en la página.');
        }

        // Walmart, Bodega Aurrera y Sam's dicen en la página de qué tienda es el precio
        $storeId = $this->pageStoreId($html);

        return $found + [
            'presentation' => null,
            'image' => $this->pageImage($html),
            'store_id' => $storeId,
            'store' => $storeId ? "Tienda {$storeId}" : null,
            'source' => (string) parse_url($url, PHP_URL_HOST),
        ];
    }

    /**
     * Cookies con las que el navegador abre la página en tu tienda (Walmart, Bodega Aurrera y
     * Sam's guardan la tienda elegida en cookies). Si la tienda no las toma, la página dice de
     * qué tienda es el precio y PriceUpdater avisa.
     *
     * @return list<array{name: string, value: string, domain: string, path: string}>
     */
    private function branchCookies(?string $chain, string $url, ?string $branch, ?string $postalCode): array
    {
        if (($chain === null || (StoreBranches::CHAINS[$chain]['via'] ?? null) !== 'cookies') || (! $branch && ! $postalCode)) {
            return [];
        }

        $now = (int) (microtime(true) * 1000);
        $location = array_filter([
            'intent' => 'PICKUP',
            'storeIntent' => 'PICKUP',
            'mergeFlag' => true,
            'isDefaulted' => false,
            'pickup' => $branch ? ['nodeId' => $branch, 'timestamp' => $now, 'selectionType' => 'USER_SELECTED'] : null,
            'postalCode' => $postalCode ? ['base' => $postalCode, 'timestamp' => $now] : null,
        ], fn ($v) => $v !== null);

        $domain = '.'.preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST));
        $cookies = [['name' => 'locGuestData', 'value' => base64_encode((string) json_encode($location))], ['name' => 'hasLocData', 'value' => '1']];
        if ($branch) {
            $cookies[] = ['name' => 'assortmentStoreId', 'value' => $branch];
        }

        return array_map(fn (array $c) => $c + ['domain' => $domain, 'path' => '/'], $cookies);
    }

    /** Tienda con la que la página calculó el precio (Walmart: "location":{…,"storeId":"2344"}). */
    private function pageStoreId(string $html): ?string
    {
        return preg_match('#"location":\{[^{}]*?"storeId":"(\d+)"#', $html, $m) ? $m[1] : null;
    }

    /** Foto del artículo: og:image o la imagen del JSON-LD. */
    private function pageImage(string $html): ?string
    {
        if (preg_match('#<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\'](https://[^"\']+)#i', $html, $m)
            || preg_match('#<meta[^>]+content=["\'](https://[^"\']+)["\'][^>]+property=["\']og:image["\']#i', $html, $m)) {
            return html_entity_decode($m[1]);
        }
        foreach ($this->jsonLdProducts($html) as $node) {
            $image = $node['image'] ?? null;
            $image = is_array($image) ? ($image['url'] ?? $image[0] ?? null) : $image;
            $image = is_array($image) ? ($image['url'] ?? null) : $image;
            if (is_string($image) && str_starts_with($image, 'https://')) {
                return $image;
            }
        }

        return null;
    }

    /** HTML de la página: directo, o con el navegador real si la tienda bloquea a los programas. */
    private function html(string $url, array $cookies = []): string
    {
        $browser = config('services.price_fetch.browser_url');
        if ($browser && Str::endsWith((string) parse_url($url, PHP_URL_HOST), config('services.price_fetch.browser_hosts'))) {
            return $this->viaBrowser($url, $cookies);
        }

        try {
            return $this->get($url)->body();
        } catch (StoreBlockedException $e) {
            return $browser ? $this->viaBrowser($url, $cookies) : throw $e;
        }
    }

    /** Abre la página en el navegador de PriceTrack (con las cookies de tu tienda) y devuelve el HTML ya cargado. */
    private function viaBrowser(string $url, array $cookies = []): string
    {
        try {
            $response = Http::timeout((int) config('services.price_fetch.browser_timeout'))
                ->get(rtrim((string) config('services.price_fetch.browser_url'), '/').'/html', array_filter(['url' => $url, 'cookies' => $cookies ? json_encode($cookies) : null]));
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
        foreach ($this->jsonLdProducts($html) as $node) {
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

        return null;
    }

    /** Nodos schema.org Product de los bloques JSON-LD. */
    private function jsonLdProducts(string $html): array
    {
        preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $blocks);

        $products = [];
        foreach ($blocks[1] as $block) {
            $data = json_decode(trim($block), true);
            $nodes = is_array($data) ? (array_is_list($data) ? $data : ($data['@graph'] ?? [$data])) : [];
            foreach ($nodes as $node) {
                if (is_array($node) && in_array('Product', (array) ($node['@type'] ?? []), true)) {
                    $products[] = $node;
                }
            }
        }

        return $products;
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
