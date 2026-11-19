<?php

namespace App\Models;

use App\Services\StoreBranches;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'notes', 'groceries', 'chain', 'branch', 'branch_name'])]
class Provider extends Model
{
    use HasFactory;

    /** Tiendas que no son súper (ferretería, departamental, electrónica): su gasto va aparte. */
    public const NOT_GROCERIES = '/home ?depot|ferreter|sodimac|truper|lowe[\'’]?s|office ?depot|office ?max|liverpool|coppel|elektra|best ?buy|ikea|sears|suburbia/iu';

    protected function casts(): array
    {
        return ['groceries' => 'boolean'];
    }

    /** Por el nombre: ¿es súper? ("Home Depot Coapa" → no). */
    public static function guessGroceries(?string $name): bool
    {
        return ! preg_match(self::NOT_GROCERIES, (string) $name);
    }

    /** Nombre de la cadena (Chedraui, Walmart…) si se conoce. */
    public function chainName(): ?string
    {
        return StoreBranches::CHAINS[$this->chain]['name'] ?? null;
    }

    /** Sucursal elegida para mostrar ("Automática", "CHEDRAUI SELECTO …"), o null. */
    public function branchLabel(): ?string
    {
        return StoreBranches::label($this);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }
}
