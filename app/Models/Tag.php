<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Etiqueta de recetas ("Mealprep", "Alta en proteína"). */
#[Fillable(['name'])]
class Tag extends Model
{
    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class);
    }

    /** @return Collection<int, string> */
    public static function names(): Collection
    {
        return static::orderBy('name')->pluck('name');
    }

    /**
     * Ids de las etiquetas con esos nombres, reusando la escritura existente
     * ("mealprep" → "Mealprep") y creando las nuevas.
     *
     * @param  iterable<int, string>  $names
     * @return array<int, int>
     */
    public static function idsFor(iterable $names): array
    {
        $key = static fn (string $v) => Str::lower(Str::ascii($v));
        $existing = static::all()->keyBy(fn (Tag $t) => $key($t->name));
        $ids = [];

        foreach ($names as $name) {
            $name = Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $name)), 60, '');
            if ($name === '') {
                continue;
            }
            $tag = $existing->get($key($name)) ?? static::create(['name' => $name]);
            $existing->put($key($name), $tag);
            $ids[$tag->id] = $tag->id;
        }

        return array_values($ids);
    }

    /** "Mealprep, Rápidas" o ["Mealprep", "Rápidas"] → lista de nombres. */
    public static function split(mixed $value): array
    {
        $list = is_array($value) ? $value : preg_split('/[,;\n]/u', (string) $value);

        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $list), fn ($v) => $v !== ''));
    }
}
