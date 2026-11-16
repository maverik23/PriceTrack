<?php

namespace App\Models;

use App\Enums\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'provider_id', 'shopping_list_id', 'price', 'pack_qty', 'size', 'unit', 'bulk', 'observed_on', 'notes'])]
class Price extends Model
{
    use HasFactory;

    /**
     * Si para cubrir la cantidad falta menos de esto (15 % de un paquete) no se cuenta otro:
     * 946 ml ≈ 1 L y 907 g (2 lb) ≈ 1 kg, pero 7 latas siguen siendo 7.
     */
    public const PACK_TOLERANCE = 0.15;

    protected function casts(): array
    {
        return [
            'unit' => Unit::class,
            'observed_on' => 'date',
            'price' => 'float',
            'pack_qty' => 'integer',
            'size' => 'float',
            'bulk' => 'boolean',
            'base_qty' => 'float',
            'unit_price' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // Normaliza siempre a unidad base (ml, g, pza) para poder comparar.
        static::saving(function (Price $price) {
            // A granel no hay paquetes: el precio es por kg, por L, por 100 g…
            $price->pack_qty = $price->bulk ? 1 : max(1, (int) $price->pack_qty);
            $price->base_qty = round($price->pack_qty * $price->size * $price->unit->toBase(), 3);
            $price->unit_price = $price->base_qty > 0 ? round($price->price / $price->base_qty, 6) : 0;
        });
    }

    /**
     * Registra el precio de una tienda con la fecha de la lista (o de hoy, fuera de
     * listas). Si ese día ya se capturó desde la misma lista, corrige ese registro;
     * si no, crea uno nuevo y el historial anterior se conserva.
     *
     * @param  array{price: float, pack_qty?: int, size: float, unit: Unit|string, bulk?: bool}  $data
     */
    public static function record(Product $product, int $providerId, ?ShoppingList $list, array $data): self
    {
        $price = static::firstOrNew([
            'product_id' => $product->id,
            'provider_id' => $providerId,
            'shopping_list_id' => $list?->id,
            'observed_on' => ($list?->priceDate() ?? today())->toDateString(),
        ]);
        $price->fill(['pack_qty' => 1, 'bulk' => false, ...$data])->save();

        return $price;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    /** Precio por unidad comparativa: $/L, $/kg o $/pza. */
    public function comparePrice(): float
    {
        return $this->unit_price * $this->unit->compareFactor();
    }

    public function compareLabel(): string
    {
        return '/'.$this->unit->compareLabel();
    }

    public function presentationLabel(): string
    {
        $content = qty($this->size).' '.$this->unit->label();

        if ($this->bulk) {
            return 'por '.($this->size == 1 ? $this->unit->label() : $content);
        }

        return $this->pack_qty > 1 ? $this->pack_qty.' × '.$content : $content;
    }

    /**
     * Paquetes completos que hay que comprar para tener $baseQty (ml, g, pza).
     * resources/js/list-grid.js (packs) lo replica.
     */
    public function packsFor(float $baseQty): int
    {
        return max(1, (int) ceil(round($baseQty / $this->base_qty - self::PACK_TOLERANCE, 6)));
    }

    /**
     * Lo que cuesta llevarse $baseQty aquí: a granel, lo justo (610 g a $54/kg = $32.94);
     * si no, paquetes completos (1 kg en un paquete de 2 kg = el paquete de 2 kg).
     */
    public function costFor(float $baseQty): float
    {
        return round($this->bulk
            ? $baseQty * $this->price / $this->base_qty
            : $this->packsFor($baseQty) * $this->price, 2);
    }
}
