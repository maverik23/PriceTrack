<?php

namespace App\Services;

use App\Enums\Unit;
use App\Support\Barcode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Busca en internet los datos de un artículo por su código de barras para
 * autocompletar el formulario (nombre, marca, categoría y presentación).
 *
 * Fuentes gratuitas y sin llave: Open Food Facts y sus bases hermanas de
 * cosméticos, productos del hogar y mascotas (mismo API, consultadas en
 * paralelo) y, si ahí no aparece, UPCitemdb (100 consultas al día por IP).
 * El resultado se guarda en caché: 30 días si se encontró, 1 día si no.
 */
class BarcodeLookup
{
    public const OPEN_FACTS = [
        'Open Food Facts' => 'https://world.openfoodfacts.org',
        'Open Beauty Facts' => 'https://world.openbeautyfacts.org',
        'Open Products Facts' => 'https://world.openproductsfacts.org',
        'Open Pet Food Facts' => 'https://world.openpetfoodfacts.org',
    ];

    public const UPCITEMDB = 'https://api.upcitemdb.com/prod/trial/lookup';

    private const OPEN_FACTS_FIELDS = 'product_name_es,product_name,product_name_en,generic_name_es,generic_name,'
        .'brands,quantity,product_quantity,product_quantity_unit,categories_tags,image_front_small_url,image_small_url';

    /** Categorías de Open Food Facts → pasillo del súper (gana la primera que coincida). */
    private const OPEN_FACTS_CATEGORIES = [
        'Lácteos' => ['en:dairies', 'en:milks', 'en:cheeses', 'en:yogurts'],
        'Bebidas' => ['en:beverages'],
        'Panadería' => ['en:breads', 'en:pastries', 'en:viennoiseries'],
        'Frutas y verduras' => ['en:fresh-fruits', 'en:fresh-vegetables'],
        'Carnes y pescados' => ['en:meats', 'en:fishes', 'en:seafood'],
        'Congelados' => ['en:frozen-foods'],
        'Botanas y dulces' => ['en:snacks'],
    ];

    /** Si ninguna categoría coincide, la de la base donde apareció. */
    private const SOURCE_CATEGORY = [
        'Open Food Facts' => 'Abarrotes',
        'Open Beauty Facts' => 'Cuidado personal',
        'Open Pet Food Facts' => 'Mascotas',
    ];

    /** Taxonomía de Google (UPCitemdb) → pasillo; se busca el texto en orden. */
    private const UPC_CATEGORIES = [
        'dairy' => 'Lácteos',
        '> beverages' => 'Bebidas',
        'bakery' => 'Panadería',
        'fruits & vegetables' => 'Frutas y verduras',
        'meat' => 'Carnes y pescados',
        'seafood' => 'Carnes y pescados',
        'snack' => 'Botanas y dulces',
        'candy' => 'Botanas y dulces',
        'cleaning' => 'Limpieza',
        'laundry' => 'Limpieza',
        'personal care' => 'Cuidado personal',
        'pet supplies' => 'Mascotas',
        'food' => 'Abarrotes',
    ];

    /** Unidad escrita en el empaque → [factor, unidad de la app]. */
    private const METRIC = [
        'ml' => [1, Unit::Ml], 'cl' => [10, Unit::Ml],
        'l' => [1, Unit::L], 'lt' => [1, Unit::L], 'lts' => [1, Unit::L], 'litro' => [1, Unit::L], 'litros' => [1, Unit::L],
        'g' => [1, Unit::G], 'gr' => [1, Unit::G], 'grs' => [1, Unit::G], 'gramo' => [1, Unit::G], 'gramos' => [1, Unit::G],
        'kg' => [1, Unit::Kg], 'kgs' => [1, Unit::Kg], 'kilo' => [1, Unit::Kg], 'kilos' => [1, Unit::Kg],
        'pza' => [1, Unit::Item], 'pzas' => [1, Unit::Item], 'pieza' => [1, Unit::Item], 'piezas' => [1, Unit::Item],
        'unidades' => [1, Unit::Item], 'unit' => [1, Unit::Item], 'units' => [1, Unit::Item], 'ct' => [1, Unit::Item],
    ];

    /** Productos de EE. UU. (Costco, Amazon): se convierten a ml / g. */
    private const IMPERIAL = [
        'floz' => [29.5735, Unit::Ml], 'gal' => [3785.41, Unit::Ml],
        'oz' => [28.3495, Unit::G], 'lb' => [453.592, Unit::G], 'lbs' => [453.592, Unit::G],
    ];

    /**
     * Datos para autocompletar, o null si ninguna fuente conoce el código.
     *
     * @return array{name: string, brand: ?string, category: ?string, size: ?float, unit: ?string, presentation: ?string, image: ?string, source: string, source_url: string}|null
     *
     * @throws RuntimeException si la búsqueda está desactivada o no se pudo consultar
     */
    public function find(string $barcode): ?array
    {
        if (! config('services.barcode_lookup.enabled')) {
            throw new RuntimeException('La búsqueda en internet está desactivada (BARCODE_LOOKUP=false).');
        }
        if (! Barcode::isGtin($barcode)) {
            return null;
        }

        $key = 'barcode-lookup:'.$barcode;
        if (is_array($cached = Cache::get($key))) {
            return $cached['data'];
        }

        $failed = false;
        $data = $this->fromOpenFacts($barcode, $failed) ?? $this->fromUpcItemDb($barcode, $failed);

        // Sin respuesta clara no se guarda en caché: se vuelve a intentar la próxima vez
        if (! $data && $failed) {
            throw new RuntimeException('No se pudo consultar internet. Intenta de nuevo en un momento.');
        }

        Cache::put($key, ['data' => $data], $data ? now()->addDays(30) : now()->addDay());

        return $data;
    }

    /**
     * Presentación escrita en el empaque: "6 x 355 ml" → 2130 ml, "1,5 kg", "500g", "30 piezas", "12 fl oz"…
     *
     * @return array{size: float, unit: string, label: string}|null size y unit son el contenido total
     */
    public static function parseQuantity(?string $text): ?array
    {
        $s = Str::lower(Str::ascii(str_replace('×', 'x', (string) $text)));
        $s = preg_replace(['/(\d),(\d)/', '/fl\.?\s*oz/'], ['$1.$2', 'floz'], $s);

        // El sistema métrico manda: "355 ml (12 fl oz)" → 355 ml
        foreach ([self::METRIC, self::IMPERIAL] as $units) {
            $pattern = '/(?:(\d+)\s*[x*]\s*)?(\d+(?:\.\d+)?)\s*('.implode('|', array_keys($units)).')\b/';
            if (! preg_match($pattern, $s, $m)) {
                continue;
            }

            [$factor, $unit] = $units[$m[3]];
            $size = round((float) $m[2] * $factor, $factor == 1 ? 3 : 1);
            $count = max(1, (int) $m[1]);
            if ($size <= 0) {
                return null;
            }

            $label = num($size).' '.$unit->label();

            return [
                'size' => round($size * $count, 3),
                'unit' => $unit->value,
                'label' => $count > 1 ? "{$count} × {$label}" : $label,
            ];
        }

        return null;
    }

    private function fromOpenFacts(string $barcode, bool &$failed): ?array
    {
        $responses = Http::pool(fn (Pool $pool) => collect(self::OPEN_FACTS)->map(
            fn (string $base, string $source) => $this->client($pool->as($source))
                ->get("{$base}/api/v2/product/{$barcode}.json", ['fields' => self::OPEN_FACTS_FIELDS, 'lc' => 'es'])
        )->values()->all());

        foreach (self::OPEN_FACTS as $source => $base) {
            $response = $responses[$source] ?? null;
            if (! $response instanceof Response || $response->serverError() || $response->status() === 429) {
                // Las bases hermanas son más chicas; solo cuenta si falla la principal
                $failed = $failed || $source === 'Open Food Facts';

                continue;
            }

            $product = $response->successful() ? $response->json('product') : null;
            if (is_array($product) && ($data = $this->fromOpenFactsProduct($product, $source, $base, $barcode))) {
                return $data;
            }
        }

        return null;
    }

    private function fromOpenFactsProduct(array $p, string $source, string $base, string $barcode): ?array
    {
        $name = collect(['product_name_es', 'product_name', 'product_name_en', 'generic_name_es', 'generic_name'])
            ->map(fn (string $field) => $this->clean($p[$field] ?? null))
            ->first(fn (?string $value) => $value !== null);
        if (! $name) {
            return null;
        }

        $tags = array_filter((array) ($p['categories_tags'] ?? []), 'is_string');
        $category = collect(self::OPEN_FACTS_CATEGORIES)->search(fn (array $needles) => array_intersect($needles, $tags) !== [])
            ?: (Str::contains(implode(' ', $tags), ['clean', 'detergent', 'laundry', 'dishwash']) ? 'Limpieza' : null)
            ?: (self::SOURCE_CATEGORY[$source] ?? null);

        return $this->result(
            name: $name,
            brand: $this->clean(Str::before((string) ($p['brands'] ?? ''), ',')),
            category: $category,
            presentation: self::parseQuantity($p['quantity'] ?? null)
                ?? self::parseQuantity(($p['product_quantity'] ?? '').' '.($p['product_quantity_unit'] ?? '')),
            image: $p['image_front_small_url'] ?? $p['image_small_url'] ?? null,
            source: $source,
            url: "{$base}/product/{$barcode}",
        );
    }

    private function fromUpcItemDb(string $barcode, bool &$failed): ?array
    {
        try {
            $response = $this->client(Http::createPendingRequest())->get(self::UPCITEMDB, ['upc' => $barcode]);
        } catch (ConnectionException) {
            $failed = true;

            return null;
        }

        // 429 = se acabaron las 100 consultas del día
        if ($response->serverError() || $response->status() === 429) {
            $failed = true;

            return null;
        }

        $item = $response->successful() ? (array) $response->json('items.0') : [];
        $title = $this->clean($item['title'] ?? null);
        if (! $title) {
            return null;
        }

        $category = Str::lower((string) ($item['category'] ?? ''));

        return $this->result(
            name: $title,
            brand: $this->clean($item['brand'] ?? null),
            category: collect(self::UPC_CATEGORIES)->first(fn (string $aisle, string $needle) => str_contains($category, $needle)),
            presentation: self::parseQuantity($item['size'] ?? null) ?? self::parseQuantity($title),
            image: $item['images'][0] ?? null,
            source: 'UPCitemdb',
            url: 'https://www.upcitemdb.com/upc/'.$barcode,
        );
    }

    private function result(string $name, ?string $brand, ?string $category, ?array $presentation, ?string $image, string $source, string $url): array
    {
        // Como en el resto de la app, el nombre lleva la presentación: "Leche entera 1 L"
        if ($presentation && ! self::parseQuantity($name)) {
            $name .= ' '.$presentation['label'];
        }

        return [
            'name' => Str::limit($name, 150, ''),
            'brand' => $brand ? Str::limit($brand, 100, '') : null,
            'category' => $category,
            'size' => $presentation['size'] ?? null,
            'unit' => $presentation['unit'] ?? null,
            // Contenido total, en el formato que entiende la hoja de la lista
            'presentation' => $presentation ? num($presentation['size']).' '.Unit::from($presentation['unit'])->label() : null,
            'image' => is_string($image) && str_starts_with($image, 'https://') ? $image : null,
            'source' => $source,
            'source_url' => $url,
        ];
    }

    private function client(PendingRequest $request): PendingRequest
    {
        return $request->acceptJson()
            ->withUserAgent((string) config('services.barcode_lookup.user_agent'))
            ->connectTimeout(4)
            ->timeout((int) config('services.barcode_lookup.timeout'));
    }

    private function clean(mixed $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5)));

        return $value === '' ? null : $value;
    }
}
