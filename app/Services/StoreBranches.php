<?php

namespace App\Services;

use App\Models\ProductLink;
use App\Models\Provider;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * Sucursal de cada proveedor. Muchas cadenas cambian el precio por sucursal; cada proveedor
 * dice de qué cadena es (`providers.chain`, se adivina por el nombre o la dirección) y en qué
 * sucursal compras (`providers.branch`: el id de la tienda o "auto"; `branch_name` para mostrarla).
 *
 * Por cadena (CHAINS):
 * - `list`: se pueden listar sus sucursales cerca del código postal (branches()).
 * - `ask`: sin sucursal elegida se pregunta antes de consultar (BranchRequiredException → 409).
 * - `by_branch`: el precio en línea cambia por sucursal y se pide el de la tuya.
 * - `via`: cómo se pasa la sucursal al consultar: `seller` (Chedraui: simulación del carrito
 *   en esa tienda), `cookies` (Walmart, Bodega Aurrera, Sam's: el navegador de PriceTrack abre la
 *   página con la tienda elegida y se revisa de qué tienda salió el precio) o `label` (solo se anota).
 *
 * Para otra cadena: su entrada en CHAINS y, si se pueden listar, cómo en branches().
 */
class StoreBranches
{
    /** Sucursal "automática": la más cercana que tenga el artículo, o la que asigne la tienda. */
    public const AUTO = 'auto';

    /** @var array<string, array{name: string, hosts: list<string>, match: string, pattern: string, list: bool, ask: bool, by_branch: bool, via: string, hint: string}> */
    public const CHAINS = [
        'chedraui' => [
            'name' => 'Chedraui',
            'hosts' => ['chedraui.com.mx'],
            'match' => '/chedraui/i',
            'pattern' => '/^chedrauimx\w+$/',
            'list' => true,
            'ask' => true,
            'by_branch' => true,
            'via' => 'seller',
            'hint' => 'Cada sucursal tiene su precio: se pide el de la tuya (o el de la más cercana que lo tenga).',
        ],
        'costco' => [
            'name' => 'Costco',
            'hosts' => ['costco.com.mx'],
            'match' => '/costco/i',
            'pattern' => '/^[\pL\pN][\pL\pN .\'-]{0,59}$/u',
            'list' => true,
            'ask' => false,
            'by_branch' => false,
            'via' => 'label',
            'hint' => 'Costco publica en línea un solo precio para todas sus bodegas; la tuya se anota con el precio.',
        ],
        'walmart' => [
            'name' => 'Walmart',
            'hosts' => ['walmart.com.mx'],
            'match' => '/wal-?mart|superama/i',
            'pattern' => '/^\d{1,6}$/',
            'list' => false,
            'ask' => false,
            'by_branch' => true,
            'via' => 'cookies',
            'hint' => 'Escribe el número de tu tienda (sale en walmart.com.mx al elegir tu tienda). Si la página da el precio de otra tienda, se avisa.',
        ],
        'bodega' => [
            'name' => 'Bodega Aurrera',
            'hosts' => ['bodegaaurrera.com.mx'],
            'match' => '/aurrer[aá]|\bbodega\b/iu',
            'pattern' => '/^\d{1,6}$/',
            'list' => false,
            'ask' => false,
            'by_branch' => true,
            'via' => 'cookies',
            'hint' => 'Escribe el número de tu tienda (sale en la página al elegir tu tienda). Si la página da el precio de otra tienda, se avisa.',
        ],
        'sams' => [
            'name' => "Sam's Club",
            'hosts' => ['sams.com.mx'],
            'match' => '/\bsam[\'’]?s\b/iu',
            'pattern' => '/^\d{1,6}$/',
            'list' => false,
            'ask' => false,
            'by_branch' => true,
            'via' => 'cookies',
            'hint' => 'Escribe el número de tu club (sale en sams.com.mx al elegir tu club). Si la página da el precio de otro club, se avisa.',
        ],
        'soriana' => [
            'name' => 'Soriana',
            'hosts' => ['soriana.com'],
            'match' => '/soriana/i',
            'pattern' => '/^[\w-]{1,20}$/',
            'list' => false,
            'ask' => false,
            'by_branch' => true,
            'via' => 'label',
            'hint' => 'Anota tu sucursal. Soriana aún no deja elegirla al consultar: el precio es el que su página da a tu zona.',
        ],
    ];

    public function __construct(private PriceFetcher $fetcher) {}

    /** La cadena de una dirección (por su dominio). */
    public static function chainFor(?string $url): ?string
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        foreach (self::CHAINS as $key => $chain) {
            foreach ($chain['hosts'] as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /** La cadena que parece por el nombre del proveedor ("Sam's Club Universidad" → sams). */
    public static function guess(?string $name): ?string
    {
        foreach (self::CHAINS as $key => $chain) {
            if (preg_match($chain['match'], (string) $name)) {
                return $key;
            }
        }

        return null;
    }

    /** La cadena de un proveedor: la que tiene puesta o, si no, la de la dirección. */
    public static function chainOf(Provider $provider, ?string $url = null): ?string
    {
        return self::chainFor($url) ?? (isset(self::CHAINS[$provider->chain]) ? $provider->chain : null);
    }

    /** La sucursal elegida en ese proveedor (null = automática o sin elegir). */
    public static function selected(Provider $provider): ?string
    {
        return $provider->branch === self::AUTO ? null : $provider->branch;
    }

    /** "CHEDRAUI SELECTO UNIVERSIDAD", "Tienda 2344", "Automática" o null sin elegir. */
    public static function label(Provider $provider): ?string
    {
        return match (true) {
            ! $provider->branch => null,
            $provider->branch === self::AUTO => 'Automática',
            default => $provider->branch_name ?: 'Tienda '.$provider->branch,
        };
    }

    /**
     * Qué le falta a ese proveedor para consultar (null = nada): el código postal o la sucursal.
     * Solo en las cadenas que la piden antes de consultar.
     *
     * @return array{provider_id: int, provider: string, chain: string, name: string, postal_code: ?string, missing: string, listable: bool}|null
     */
    public static function missing(Provider $provider, ?string $url = null): ?array
    {
        $chain = self::chainOf($provider, $url);
        if (! $chain || ! self::CHAINS[$chain]['ask']) {
            return null;
        }

        $postalCode = Setting::value(Setting::POSTAL_CODE);
        $missing = match (true) {
            ! $postalCode => 'postal_code',
            ! $provider->branch => 'branch',
            default => null,
        };

        return $missing ? self::info($provider, $chain) + ['missing' => $missing] : null;
    }

    /** @throws BranchRequiredException si a ese proveedor le falta el código postal o la sucursal */
    public static function ensure(ProductLink $link): void
    {
        if ($link->provider && $info = self::missing($link->provider, $link->url)) {
            throw new BranchRequiredException($info);
        }
    }

    /**
     * Sucursales de una cadena cerca de un código postal, de la más cercana a la más lejana.
     *
     * @return array<string, string> id => "CHEDRAUI SELECTO … · 3.1 km"
     */
    public function branches(string $chain, string $postalCode): array
    {
        return match ($chain) {
            'chedraui' => $this->fetcher->chedrauiStores($postalCode),
            'costco' => $this->fetcher->costcoStores($postalCode),
            default => [],
        };
    }

    /**
     * Los proveedores de alguna cadena, para el navegador (resources/js/online-prices.js):
     * su cadena y qué les falta.
     *
     * @return array<int, array{provider_id: int, provider: string, chain: string, name: string, postal_code: ?string, missing: ?string, listable: bool}>
     */
    public static function forPage(): array
    {
        return Provider::whereNotNull('chain')->get()
            ->filter(fn (Provider $p) => isset(self::CHAINS[$p->chain]))
            ->mapWithKeys(fn (Provider $p) => [$p->id => self::info($p, $p->chain) + ['missing' => self::missing($p)['missing'] ?? null]])
            ->all();
    }

    public static function isValid(string $chain, ?string $branch): bool
    {
        return $branch === null || $branch === self::AUTO || (bool) preg_match(self::CHAINS[$chain]['pattern'], $branch);
    }

    /**
     * Guarda la sucursal de un proveedor (y el código postal). Con otro código postal cambian
     * las sucursales cercanas: las de los demás proveedores que se eligen de una lista se borran.
     */
    public static function save(Provider $provider, string $chain, string $postalCode, ?string $branch, ?string $branchName = null): void
    {
        self::changePostalCode($postalCode, except: $provider);

        $provider->forceFill([
            'chain' => $provider->chain ?? $chain,
            'branch' => $branch ?: self::AUTO,
            'branch_name' => $branch && $branch !== self::AUTO && $branchName ? Str::limit(Str::before($branchName, ' · '), 150, '') : null,
        ])->save();
    }

    /** Guarda el código postal; si cambió, se vuelve a elegir la sucursal donde salía de una lista. */
    public static function changePostalCode(?string $postalCode, ?Provider $except = null): bool
    {
        if ($postalCode === Setting::value(Setting::POSTAL_CODE)) {
            return false;
        }

        Setting::put(Setting::POSTAL_CODE, $postalCode);
        $listable = array_keys(array_filter(self::CHAINS, fn (array $c) => $c['list']));
        Provider::whereIn('chain', $listable)
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->update(['branch' => null, 'branch_name' => null]);

        return true;
    }

    /** Opciones para elegir la cadena de un proveedor: clave => nombre. */
    public static function options(): array
    {
        return array_map(fn (array $c) => $c['name'], self::CHAINS);
    }

    /** @return array{provider_id: int, provider: string, chain: string, name: string, postal_code: ?string, listable: bool, ask: bool} */
    private static function info(Provider $provider, string $chain): array
    {
        return [
            'provider_id' => $provider->id,
            'provider' => $provider->name,
            'chain' => $chain,
            'name' => self::CHAINS[$chain]['name'],
            'postal_code' => Setting::value(Setting::POSTAL_CODE),
            'listable' => self::CHAINS[$chain]['list'],
            'ask' => self::CHAINS[$chain]['ask'],
        ];
    }
}
