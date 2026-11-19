<?php

namespace Tests\Unit;

use App\Support\RecipeData;
use PHPUnit\Framework\TestCase;

/** El texto de preparación (un paso por línea) que aún aceptan el formulario viejo y el MCP. */
class InstructionsTextTest extends TestCase
{
    public function test_numbering_stages_minutes_and_notes(): void
    {
        [$steps, $notes] = RecipeData::parseInstructions("1) Remoja el frijol\n\n# Cocción\n2. Cuécelo (90 minutos)\nNota: no lo sales al inicio\nPaso 3 - Sala al final");

        $this->assertSame([
            ['stage' => null, 'body' => 'Remoja el frijol', 'minutes' => null],
            ['stage' => 'Cocción', 'body' => 'Cuécelo', 'minutes' => 90],
            ['stage' => 'Cocción', 'body' => 'Sala al final', 'minutes' => null],
        ], $steps);
        $this->assertSame(['no lo sales al inicio'], $notes);
    }

    public function test_numbers_that_are_part_of_the_step_are_kept(): void
    {
        [$steps] = RecipeData::parseInstructions("2 tazas de agua al fuego\n10 minutos de reposo");

        $this->assertSame(['2 tazas de agua al fuego', '10 minutos de reposo'], array_column($steps, 'body'));
    }
}
