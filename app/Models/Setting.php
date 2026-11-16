<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Ajustes de la app en la base (clave → valor). */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public const POSTAL_CODE = 'postal_code';

    /** Vendedor de la tienda Chedraui elegida (chedrauimx0262); vacío = automática. */
    public const CHEDRAUI_STORE = 'chedraui_store';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public static function value(string $key, ?string $default = null): ?string
    {
        return static::find($key)?->value ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
