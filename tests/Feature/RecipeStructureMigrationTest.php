<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La migración que da estructura a las recetas conserva los datos: parte `instructions`
 * en pasos y pasa las líneas "Nota — …" a las notas. Corre en una base SQLite en memoria
 * propia para poder regresar y repetir la migración sin tocar la base de los demás tests.
 */
class RecipeStructureMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.migration_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        config(['database.default' => 'migration_test']);
        DB::purge('migration_test');
        Artisan::call('migrate:fresh', ['--database' => 'migration_test']);
        Artisan::call('migrate:rollback', ['--database' => 'migration_test', '--step' => $this->stepsSinceStructure()]);
    }

    /** Cuántas migraciones hay desde la que da estructura a las recetas (inclusive). */
    private function stepsSinceStructure(): int
    {
        return count(array_filter(glob(database_path('migrations/*.php')), fn ($f) => basename($f) >= '2026_11_21_000002'));
    }

    public function test_instructions_become_steps_and_note_lines_move_to_notes(): void
    {
        $this->assertTrue(Schema::hasColumn('recipes', 'instructions'));
        $now = now();
        $long = DB::table('recipes')->insertGetId([
            'name' => 'Albóndigas', 'servings' => 4, 'notes' => 'De la abuela', 'created_at' => $now, 'updated_at' => $now,
            'instructions' => "Mezcla la carne con el arroz.\n\n  Forma las albóndigas.  \nNota — Se pueden congelar crudas.\nCuece en el caldo 25 minutos.\nnota - sin hierbabuena también quedan bien",
        ]);
        $plain = DB::table('recipes')->insertGetId([
            'name' => 'Agua de limón', 'servings' => 2, 'notes' => null, 'created_at' => $now, 'updated_at' => $now,
            'instructions' => 'Nota — Usa limones sin semilla.',
        ]);
        $empty = DB::table('recipes')->insertGetId(['name' => 'Sin pasos', 'servings' => 1, 'instructions' => null, 'created_at' => $now, 'updated_at' => $now]);

        Artisan::call('migrate', ['--database' => 'migration_test']);

        $this->assertFalse(Schema::hasColumn('recipes', 'instructions'));
        $steps = DB::table('recipe_steps')->where('recipe_id', $long)->orderBy('position')->get();
        $this->assertSame(['Mezcla la carne con el arroz.', 'Forma las albóndigas.', 'Cuece en el caldo 25 minutos.'], $steps->pluck('body')->all());
        $this->assertSame([0, 1, 2], $steps->pluck('position')->map(fn ($p) => (int) $p)->all());
        $this->assertSame("De la abuela\n\nSe pueden congelar crudas.\nsin hierbabuena también quedan bien", DB::table('recipes')->find($long)->notes);

        $this->assertSame(0, DB::table('recipe_steps')->where('recipe_id', $plain)->count());
        $this->assertSame('Usa limones sin semilla.', DB::table('recipes')->find($plain)->notes);
        $this->assertSame(0, DB::table('recipe_steps')->where('recipe_id', $empty)->count());

        // Notas largas: ya no se cortan en 255
        DB::table('recipes')->where('id', $plain)->update(['notes' => str_repeat('a', 3000)]);
        $this->assertSame(3000, strlen(DB::table('recipes')->find($plain)->notes));

        // Y se puede regresar: los pasos vuelven a ser texto
        Artisan::call('migrate:rollback', ['--database' => 'migration_test', '--step' => $this->stepsSinceStructure()]);
        $this->assertSame("Mezcla la carne con el arroz.\nForma las albóndigas.\nCuece en el caldo 25 minutos.", DB::table('recipes')->find($long)->instructions);
    }
}
