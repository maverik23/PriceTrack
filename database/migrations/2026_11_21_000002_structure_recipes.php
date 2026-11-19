<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recetas con estructura: pasos por etapa, secciones de ingredientes, sub-recetas
 * (un ingrediente que es otra receta), rendimiento, calificación, etiquetas, notas
 * largas y una nota por día en el menú.
 *
 * Los datos existentes se conservan: `instructions` (un paso por línea) se parte en
 * pasos y las líneas "Nota — …" (las puso el importador porque no cabían en las notas)
 * se pasan a las notas de la receta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->text('notes')->nullable()->change();
            // Cuánto rinde en peso o volumen (1200 g de crema): permite usarla por g/ml como sub-receta
            $table->decimal('yield_qty', 10, 3)->nullable()->after('servings');
            $table->string('yield_unit', 10)->nullable()->after('yield_qty');
            $table->unsignedTinyInteger('rating')->nullable()->after('minutes');
        });

        Schema::table('recipe_ingredients', function (Blueprint $table) {
            // Ingrediente que es otra receta (crema de verduras casera); null = artículo o texto libre
            $table->foreignId('subrecipe_id')->nullable()->after('product_id')->constrained('recipes')->nullOnDelete();
            // "Salsa", "Para servir"…: se agrupan al mostrar y al editar
            $table->string('section', 80)->nullable()->after('note');
        });

        Schema::create('recipe_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            // "Salsa", "Albóndigas", "Montaje"; null = sin etapa
            $table->string('stage', 80)->nullable();
            $table->text('body');
            // Para el temporizador del modo cocinar
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->timestamps();
            $table->index(['recipe_id', 'position']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->timestamps();
        });

        Schema::create('recipe_tag', function (Blueprint $table) {
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['recipe_id', 'tag_id']);
        });

        Schema::create('meal_day_notes', function (Blueprint $table) {
            $table->id();
            $table->date('planned_on')->unique();
            $table->string('body', 500);
            $table->timestamps();
        });

        $this->splitInstructions();

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('instructions');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->text('instructions')->nullable()->after('minutes');
        });

        // Los pasos vuelven a ser texto, un paso por línea (la etapa como "# Etapa")
        foreach (DB::table('recipe_steps')->orderBy('recipe_id')->orderBy('position')->get()->groupBy('recipe_id') as $recipeId => $steps) {
            $lines = [];
            $stage = null;
            foreach ($steps as $step) {
                if ($step->stage !== $stage && $step->stage !== null) {
                    $lines[] = '# '.$step->stage;
                }
                $stage = $step->stage;
                $lines[] = $step->body;
            }
            DB::table('recipes')->where('id', $recipeId)->update(['instructions' => implode("\n", $lines)]);
        }

        Schema::dropIfExists('meal_day_notes');
        Schema::dropIfExists('recipe_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('recipe_steps');
        Schema::table('recipe_ingredients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subrecipe_id');
            $table->dropColumn('section');
        });
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn(['yield_qty', 'yield_unit', 'rating']);
        });
    }

    /** Un paso por línea; las líneas "Nota — …" van a las notas de la receta. */
    private function splitInstructions(): void
    {
        $now = now();

        foreach (DB::table('recipes')->whereNotNull('instructions')->orderBy('id')->get(['id', 'instructions', 'notes']) as $recipe) {
            $steps = [];
            $notes = [];

            foreach (preg_split('/\R/u', (string) $recipe->instructions) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^nota\s*(?:—|–|-|:)\s*(.*)$/iu', $line, $m)) {
                    $notes[] = trim($m[1]);

                    continue;
                }
                $steps[] = [
                    'recipe_id' => $recipe->id,
                    'position' => count($steps),
                    'stage' => null,
                    'body' => $line,
                    'minutes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($steps) {
                DB::table('recipe_steps')->insert($steps);
            }
            if ($notes = array_filter($notes)) {
                $existing = trim((string) $recipe->notes);
                DB::table('recipes')->where('id', $recipe->id)->update([
                    'notes' => trim($existing."\n\n".implode("\n", $notes)),
                ]);
            }
        }
    }
};
