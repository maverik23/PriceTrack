<?php

namespace App\Enums;

/**
 * Unidades de un ingrediente: las de los artículos más las de cocina (taza,
 * cucharada, cucharadita), que son de volumen. Para pasar entre dimensiones
 * (1 taza de arroz → g, 1 cebolla → kg) se usan el peso de una taza y de una pieza
 * del artículo (ver Product::fromIngredient()).
 */
enum IngredientUnit: string
{
    case G = 'g';
    case Kg = 'kg';
    case Ml = 'ml';
    case L = 'l';
    case Item = 'item';
    case Cup = 'cup';
    case Tbsp = 'tbsp';
    case Tsp = 'tsp';

    /** Mililitros de una taza (la medida de cocina estándar en México). */
    public const CUP_ML = 240;

    public function label(): string
    {
        return match ($this) {
            self::G => 'g',
            self::Kg => 'kg',
            self::Ml => 'ml',
            self::L => 'L',
            self::Item => 'pza',
            self::Cup => 'taza',
            self::Tbsp => 'cda',
            self::Tsp => 'cdita',
        };
    }

    /** "2 tazas", "1 cda". */
    public function labelFor(float $quantity): string
    {
        return $this === self::Cup && abs($quantity - 1) > 0.0001 ? 'tazas' : $this->label();
    }

    /** volume | mass | count */
    public function dimension(): string
    {
        return match ($this) {
            self::G, self::Kg => 'mass',
            self::Item => 'count',
            default => 'volume',
        };
    }

    /** Unidades base (g, ml o pza) en 1 de esta unidad. */
    public function toBase(): float
    {
        return match ($this) {
            self::Kg, self::L => 1000.0,
            self::Cup => (float) self::CUP_ML,
            self::Tbsp => 15.0,
            self::Tsp => 5.0,
            default => 1.0,
        };
    }

    public function isKitchen(): bool
    {
        return in_array($this, [self::Cup, self::Tbsp, self::Tsp], true);
    }

    /** @return array<string, array{label: string, dimension: string, base: float}> */
    public static function meta(): array
    {
        $meta = [];
        foreach (self::cases() as $case) {
            $meta[$case->value] = ['label' => $case->label(), 'dimension' => $case->dimension(), 'base' => $case->toBase()];
        }

        return $meta;
    }
}
