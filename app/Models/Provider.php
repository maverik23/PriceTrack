<?php

namespace App\Models;

use App\Services\StoreBranches;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'notes', 'chain', 'branch', 'branch_name'])]
class Provider extends Model
{
    use HasFactory;

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
