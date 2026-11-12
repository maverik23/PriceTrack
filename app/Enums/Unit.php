<?php

namespace App\Enums;

/**
 * Unidades de medida soportadas.
 *
 * Todo se normaliza a una unidad base por dimensión (ml, g, pza) para poder
 * comparar presentaciones distintas (ej. 345 ml vs 1 L vs 6 × 1 L).
 */
enum Unit: string
{
    case Ml = 'ml';
    case L = 'l';
    case G = 'g';
    case Kg = 'kg';
    case Item = 'item';

    public function label(): string
    {
        return match ($this) {
            self::Ml => 'ml',
            self::L => 'L',
            self::G => 'g',
            self::Kg => 'kg',
            self::Item => 'pza',
        };
    }

    public function name(): string
    {
        return match ($this) {
            self::Ml => 'Mililitros (ml)',
            self::L => 'Litros (L)',
            self::G => 'Gramos (g)',
            self::Kg => 'Kilos (kg)',
            self::Item => 'Piezas (item)',
        };
    }

    /** volume | mass | count */
    public function dimension(): string
    {
        return match ($this) {
            self::Ml, self::L => 'volume',
            self::G, self::Kg => 'mass',
            self::Item => 'count',
        };
    }

    /** Cuántas unidades base (ml, g, pza) hay en 1 de esta unidad. */
    public function toBase(): float
    {
        return match ($this) {
            self::L, self::Kg => 1000.0,
            default => 1.0,
        };
    }

    /** Unidad en la que se muestra el precio comparativo: $/L, $/kg, $/pza. */
    public function compareLabel(): string
    {
        return match ($this->dimension()) {
            'volume' => 'L',
            'mass' => 'kg',
            'count' => 'pza',
        };
    }

    /** Unidades base por unidad comparativa (1 L = 1000 ml, 1 kg = 1000 g). */
    public function compareFactor(): float
    {
        return $this->dimension() === 'count' ? 1.0 : 1000.0;
    }

    public function isCompatibleWith(self $other): bool
    {
        return $this->dimension() === $other->dimension();
    }

    /** @return array<int, self> */
    public function compatibleUnits(): array
    {
        return array_values(array_filter(self::cases(), fn (self $u) => $u->isCompatibleWith($this)));
    }

    /**
     * Tabla de conversiones para el front (una sola fuente de verdad).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function meta(): array
    {
        $meta = [];
        foreach (self::cases() as $case) {
            $meta[$case->value] = [
                'label' => $case->label(),
                'dimension' => $case->dimension(),
                'base' => $case->toBase(),
                'compare' => $case->compareFactor(),
                'compare_label' => $case->compareLabel(),
            ];
        }

        return $meta;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->name();
        }

        return $options;
    }
}
