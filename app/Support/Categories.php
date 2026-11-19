<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\Income;
use App\Models\IncomeSource;
use App\Models\Product;
use App\Models\ProductLink;
use App\Models\Recipe;
use App\Models\RecurringPayment;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Las categorías (y marcas) son texto libre que se reusa: al escribir "lácteos" se guarda la
 * escritura que ya existe ("Lacteos") para no duplicar. Aquí se renombra una en todos lados
 * (con eso también se corrige la escritura) y se dice cuándo se reusó una al guardar.
 */
class Categories
{
    /**
     * Qué se puede renombrar: clave => [nombre, [[modelo, columna], …]].
     *
     * @var array<string, array{0: string, 1: list<array{0: class-string, 1: string}>}>
     */
    public const KINDS = [
        'product_category' => ['Categoría de artículo', [[Product::class, 'category']]],
        'product_brand' => ['Marca', [[Product::class, 'brand'], [ProductLink::class, 'brand']]],
        'recipe_category' => ['Categoría de receta', [[Recipe::class, 'category']]],
        'asset_category' => ['Categoría de bien', [[Asset::class, 'category']]],
        'payment_category' => ['Categoría de pago recurrente', [[RecurringPayment::class, 'category']]],
        'income_category' => ['Categoría de ingreso', [[Income::class, 'category'], [IncomeSource::class, 'category']]],
    ];

    /** Para comparar: sin mayúsculas, acentos ni espacios dobles. */
    public static function key(?string $value): string
    {
        return Str::lower(Str::ascii(trim(preg_replace('/\s+/u', ' ', (string) $value))));
    }

    /**
     * Valores usados y en cuántos registros. Se cuenta en PHP: en MySQL la intercalación no
     * distingue acentos ni mayúsculas, y "Lacteos" y "Lácteos" quedarían en un solo grupo.
     *
     * @return Collection<string, int>
     */
    public static function values(string $kind): Collection
    {
        $counts = [];
        foreach (self::rows($kind) as [, , $value]) {
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);

        return collect($counts);
    }

    /** @return \Generator<array{0: class-string, 1: int, 2: string}> modelo, id y valor */
    private static function rows(string $kind): \Generator
    {
        foreach (self::KINDS[$kind][1] as [$model, $column]) {
            foreach ($model::query()->whereNotNull($column)->where($column, '!=', '')->pluck($column, 'id') as $id => $value) {
                yield [$model, $id, $value];
            }
        }
    }

    /**
     * Renombra `$from` (sin importar mayúsculas ni acentos) a `$to` en todos sus registros.
     * Si `$to` ya existe con otra escritura, las dos se juntan con la nueva.
     *
     * @return array{renamed: array<int, string>, to: string, records: int, merged: array<int, string>}|null null si `$from` no existe
     */
    public static function rename(string $kind, string $from, string $to): ?array
    {
        $to = trim(preg_replace('/\s+/u', ' ', $to));
        $values = self::values($kind)->keys();
        $old = $values->filter(fn ($v) => self::key($v) === self::key($from))->values();
        if ($old->isEmpty()) {
            return null;
        }
        // Otra escritura de la nueva ("Reposteria" al renombrar "Postres" → "Repostería"): se junta
        $merged = $values->filter(fn ($v) => self::key($v) === self::key($to) && $v !== $to && ! $old->contains($v))->values();
        $all = $old->merge($merged)->unique()->all();

        // Por id y comparando en PHP (la base no distingue "Lacteos" de "Lácteos")
        $ids = [];
        foreach (self::rows($kind) as [$model, $id, $value]) {
            if (in_array($value, $all, true) && $value !== $to) {
                $ids[$model][] = $id;
            }
        }
        $records = 0;
        foreach (self::KINDS[$kind][1] as [$model, $column]) {
            foreach (array_chunk($ids[$model] ?? [], 500) as $chunk) {
                $records += $model::query()->whereKey($chunk)->update([$column => $to]);
            }
        }

        return ['renamed' => $old->all(), 'to' => $to, 'records' => $records, 'merged' => $merged->all()];
    }

    /**
     * Avisos cuando lo guardado no es lo que se escribió porque ya existía otra escritura.
     *
     * @param  array<string, mixed>  $given  lo que se mandó
     * @param  array<string, array{0: ?string, 1: string}>  $saved  campo => [lo que quedó, clave de KINDS]
     * @return list<string>
     */
    public static function notices(array $given, array $saved): array
    {
        $out = [];
        foreach ($saved as $field => [$value, $kind]) {
            $written = trim(preg_replace('/\s+/u', ' ', (string) ($given[$field] ?? '')));
            if ($written !== '' && $value !== null && $written !== $value) {
                $what = Str::lower(self::KINDS[$kind][0]);
                $out[] = "«{$written}» ya existía como {$what} escrita «{$value}» y se usó esa (para no duplicar). Para cambiar cómo se escribe en todos los registros usa rename-category con kind {$kind}, from «{$value}» y to «{$written}».";
            }
        }

        return $out;
    }
}
