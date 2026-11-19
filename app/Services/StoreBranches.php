<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Cadenas que cambian el precio por sucursal (hoy, Chedraui). Antes de consultar una de
 * ellas hace falta el código postal y que se haya elegido la sucursal (o "automática":
 * la más cercana que tenga el artículo). Para agregar otra cadena: su entrada en CHAINS y
 * cómo listar sus sucursales en branches(); las vistas no cambian.
 */
class StoreBranches
{
    /** Valor del ajuste cuando se eligió "automática". Sin valor = aún no se elige (se pregunta). */
    public const AUTO = 'auto';

    /** @var array<string, array{name: string, host: string, setting: string, pattern: string}> */
    public const CHAINS = [
        'chedraui' => [
            'name' => 'Chedraui',
            'host' => 'chedraui.com.mx',
            'setting' => Setting::CHEDRAUI_STORE,
            // Identificador de una sucursal (vendedor VTEX)
            'pattern' => '/^chedrauimx\w+$/',
        ],
    ];

    public function __construct(private PriceFetcher $fetcher) {}

    /** La cadena de una dirección, si cambia el precio por sucursal. */
    public static function chainFor(?string $url): ?string
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        foreach (self::CHAINS as $key => $chain) {
            if ($host === $chain['host'] || str_ends_with($host, '.'.$chain['host'])) {
                return $key;
            }
        }

        return null;
    }

    /** La sucursal elegida (null = automática o sin elegir). */
    public static function selected(string $chain): ?string
    {
        $value = Setting::value(self::CHAINS[$chain]['setting']);

        return $value === self::AUTO ? null : $value;
    }

    /**
     * Qué falta para consultar esa cadena (null = nada): el código postal o la sucursal.
     *
     * @return array{chain: string, name: string, postal_code: ?string, missing: string}|null
     */
    public static function missing(string $chain): ?array
    {
        $postalCode = Setting::value(Setting::POSTAL_CODE);
        $missing = match (true) {
            ! $postalCode => 'postal_code',
            ! Setting::value(self::CHAINS[$chain]['setting']) => 'branch',
            default => null,
        };

        return $missing ? ['chain' => $chain, 'name' => self::CHAINS[$chain]['name'], 'postal_code' => $postalCode, 'missing' => $missing] : null;
    }

    /** @throws BranchRequiredException si a esa dirección le falta el código postal o la sucursal */
    public static function ensure(?string $url): void
    {
        $chain = self::chainFor($url);
        if ($chain && $info = self::missing($chain)) {
            throw new BranchRequiredException($info);
        }
    }

    /**
     * Sucursales cerca de un código postal, de la más cercana a la más lejana.
     *
     * @return array<string, string> id => "CHEDRAUI SELECTO … · 3.1 km"
     */
    public function branches(string $chain, string $postalCode): array
    {
        return match ($chain) {
            'chedraui' => $this->fetcher->chedrauiStores($postalCode),
            default => [],
        };
    }

    /**
     * Las cadenas para el navegador (resources/js/online-prices.js): dominio y qué les falta.
     *
     * @return array<string, array{name: string, host: string, postal_code: ?string, missing: ?string}>
     */
    public static function forPage(): array
    {
        return collect(self::CHAINS)->map(fn (array $chain, string $key) => [
            'name' => $chain['name'],
            'host' => $chain['host'],
            'postal_code' => Setting::value(Setting::POSTAL_CODE),
            'missing' => self::missing($key)['missing'] ?? null,
        ])->all();
    }

    public static function isValid(string $chain, ?string $branch): bool
    {
        return $branch === null || $branch === self::AUTO || (bool) preg_match(self::CHAINS[$chain]['pattern'], $branch);
    }

    /**
     * Guarda el código postal y la sucursal de una cadena. Con otro código postal cambian
     * las sucursales cercanas: las de las demás cadenas se vuelven a preguntar.
     */
    public static function save(string $chain, string $postalCode, ?string $branch): void
    {
        if ($postalCode !== Setting::value(Setting::POSTAL_CODE)) {
            foreach (self::CHAINS as $other) {
                Setting::put($other['setting'], null);
            }
            Setting::put(Setting::POSTAL_CODE, $postalCode);
        }
        Setting::put(self::CHAINS[$chain]['setting'], $branch ?: self::AUTO);
    }
}
