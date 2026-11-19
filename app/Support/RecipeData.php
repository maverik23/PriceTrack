<?php

namespace App\Support;

use App\Enums\IngredientUnit;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Valida y guarda una receta con sus ingredientes, pasos y etiquetas: la usan el
 * formulario (RecipeController) y el servidor MCP (CreateRecipe, UpdateRecipe).
 */
class RecipeData
{
    /** Unidades en que se puede decir cuánto rinde una receta. */
    public const YIELD_UNITS = ['g', 'kg', 'ml', 'l'];

    /**
     * @param  array<string, mixed>  $input
     * @param  Recipe|null  $recipe  la receta que se edita (para impedir ciclos de sub-recetas)
     * @return array{recipe: array<string, mixed>, ingredients: array<int, array<string, mixed>>, steps: array<int, array<string, mixed>>, tags: array<int, string>}
     */
    public static function validate(array $input, ?Recipe $recipe = null): array
    {
        // Los renglones vacíos del formulario no cuentan
        $input['ingredients'] = collect($input['ingredients'] ?? [])
            ->filter(fn ($row) => is_array($row) && (filled($row['product_id'] ?? null) || filled($row['subrecipe_id'] ?? null) || filled($row['name'] ?? null)))
            ->values()->all();

        // Pasos: lista estructurada o, para quien la sigue usando, texto con un paso por línea
        $extraNotes = [];
        if (! array_key_exists('steps', $input) && array_key_exists('instructions', $input)) {
            [$input['steps'], $extraNotes] = self::parseInstructions((string) $input['instructions']);
        }
        $input['steps'] = collect($input['steps'] ?? [])
            ->filter(fn ($row) => is_array($row) && filled($row['body'] ?? $row['text'] ?? null))
            ->map(fn ($row) => ['stage' => $row['stage'] ?? null, 'body' => $row['body'] ?? $row['text'], 'minutes' => $row['minutes'] ?? null])
            ->values()->all();
        $input['tags'] = Tag::split($input['tags'] ?? []);

        $data = Validator::validate($input, [
            'name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:60'],
            'servings' => ['required', 'integer', 'min:1', 'max:999'],
            // La unidad siempre llega del formulario: sin cantidad se ignora
            'yield_qty' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'yield_unit' => ['nullable', 'required_with:yield_qty', Rule::in(self::YIELD_UNITS)],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'source_url' => ['nullable', 'url', 'max:500'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
            'ingredients' => ['array', 'max:150'],
            'ingredients.*.product_id' => ['nullable', 'exists:products,id'],
            'ingredients.*.subrecipe_id' => ['nullable', 'exists:recipes,id'],
            'ingredients.*.name' => ['nullable', 'string', 'max:150'],
            'ingredients.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'ingredients.*.unit' => ['nullable', Rule::enum(IngredientUnit::class)],
            'ingredients.*.note' => ['nullable', 'string', 'max:255'],
            'ingredients.*.section' => ['nullable', 'string', 'max:80'],
            'steps' => ['array', 'max:200'],
            'steps.*.stage' => ['nullable', 'string', 'max:80'],
            'steps.*.body' => ['required', 'string', 'max:5000'],
            'steps.*.minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ], [], [
            'servings' => 'porciones',
            'yield_qty' => 'rendimiento',
            'yield_unit' => 'unidad del rendimiento',
            'minutes' => 'tiempo',
            'rating' => 'calificación',
            'source_url' => 'dirección de origen',
            'tags' => 'etiquetas',
            'ingredients.*.quantity' => 'cantidad',
            'ingredients.*.unit' => 'unidad',
            'ingredients.*.section' => 'sección',
            'steps.*.body' => 'paso',
            'steps.*.stage' => 'etapa',
            'steps.*.minutes' => 'minutos del paso',
        ]);

        $rows = $data['ingredients'] ?? [];
        $products = Product::findMany(collect($rows)->pluck('product_id')->filter())->keyBy('id');
        $subrecipes = Recipe::findMany(collect($rows)->pluck('subrecipe_id')->filter())->keyBy('id');
        $ingredients = [];

        foreach ($rows as $i => $row) {
            $product = $products->get($row['product_id'] ?? null);
            $sub = $product ? null : $subrecipes->get($row['subrecipe_id'] ?? null);
            $unit = filled($row['unit'] ?? null) ? IngredientUnit::from($row['unit']) : null;
            $quantity = filled($row['quantity'] ?? null) ? (float) $row['quantity'] : null;

            if ($product) {
                // Con artículo la cantidad es obligatoria y de su misma dimensión: de ahí salen costo y lista
                if (! $quantity || ! $unit) {
                    throw ValidationException::withMessages(["ingredients.$i.quantity" => "Indica cantidad y unidad de «{$product->name}»."]);
                }
                if ($unit === IngredientUnit::Serving) {
                    throw ValidationException::withMessages(["ingredients.$i.unit" => "«{$product->name}» es un artículo: las porciones son para sub-recetas."]);
                }
                if ($missing = $product->missingConversion($unit)) {
                    $allowed = implode(', ', array_map(fn (IngredientUnit $u) => $u->label(), array_filter(IngredientUnit::cases(), fn ($u) => $product->fromIngredient($u, 1) !== null)));
                    throw ValidationException::withMessages(["ingredients.$i.unit" => "«{$product->name}» se mide en {$allowed}. Para usar {$unit->label()} indica en el artículo {$missing}."]);
                }
            } elseif ($sub) {
                self::checkSubrecipe($sub, $recipe, $quantity, $unit, "ingredients.$i");
            }

            $ingredients[] = [
                'product_id' => $product?->id,
                'subrecipe_id' => $sub?->id,
                'name' => $product?->name ?? $sub?->name ?? trim($row['name']),
                'quantity' => $quantity,
                'unit' => $quantity ? $unit?->value : null,
                'note' => filled($row['note'] ?? null) ? trim($row['note']) : null,
                'section' => filled($row['section'] ?? null) ? trim($row['section']) : null,
                'position' => $i,
            ];
        }

        $steps = [];
        foreach ($data['steps'] ?? [] as $i => $row) {
            $steps[] = [
                'position' => $i,
                'stage' => filled($row['stage'] ?? null) ? trim($row['stage']) : null,
                'body' => trim($row['body']),
                'minutes' => filled($row['minutes'] ?? null) ? (int) $row['minutes'] : null,
            ];
        }

        $notes = trim(implode("\n\n", array_filter([trim((string) ($data['notes'] ?? '')), implode("\n", $extraNotes)])));
        $fields = [
            'name' => trim($data['name']),
            'category' => Recipe::canonicalCategory($data['category'] ?? null),
            'servings' => (int) $data['servings'],
            'yield_qty' => filled($data['yield_qty'] ?? null) ? (float) $data['yield_qty'] : null,
            'yield_unit' => filled($data['yield_qty'] ?? null) ? $data['yield_unit'] : null,
            'minutes' => $data['minutes'] ?? null,
            'rating' => $data['rating'] ?? null,
            'source_url' => $data['source_url'] ?? null,
            'notes' => $notes === '' ? null : $notes,
        ];

        return ['recipe' => $fields, 'ingredients' => $ingredients, 'steps' => $steps, 'tags' => $data['tags'] ?? []];
    }

    /** Una sub-receta: con cantidad en porciones o en la dimensión de lo que rinde, y sin ciclos. */
    private static function checkSubrecipe(Recipe $sub, ?Recipe $recipe, ?float $quantity, ?IngredientUnit $unit, string $key): void
    {
        if ($recipe?->exists && ($sub->id === $recipe->id || in_array($recipe->id, Recipe::descendantIds($sub->id), true))) {
            throw ValidationException::withMessages(["$key.subrecipe_id" => $sub->id === $recipe->id
                ? 'Una receta no puede ser ingrediente de sí misma.'
                : "«{$sub->name}» ya usa «{$recipe->name}» (directa o indirectamente): no puede ser su ingrediente."]);
        }
        if (! $quantity || ! $unit) {
            throw ValidationException::withMessages(["$key.quantity" => "Indica cuánto se usa de «{$sub->name}» (en porciones o, si dice cuánto rinde, en g o ml)."]);
        }
        if ($unit !== IngredientUnit::Serving && $unit->dimension() !== $sub->yieldDimension()) {
            throw ValidationException::withMessages(["$key.unit" => $sub->yieldDimension()
                ? "«{$sub->name}» rinde {$sub->yieldLabel()}: usa porciones o una unidad de ".($sub->yieldDimension() === 'mass' ? 'peso' : 'volumen').'.'
                : "«{$sub->name}» no dice cuánto rinde en peso o volumen: úsala en porciones o agrégale el rendimiento."]);
        }
    }

    /**
     * Texto con un paso por línea → pasos. "# Salsa" empieza una etapa, "(15 min)" al
     * final da los minutos del paso, la numeración ("1.", "Paso 2:") se quita, y las
     * líneas "Nota — …" no son pasos: van a las notas de la receta.
     *
     * @return array{0: array<int, array{stage: ?string, body: string, minutes: ?int}>, 1: array<int, string>} pasos y notas
     */
    public static function parseInstructions(string $text): array
    {
        $steps = [];
        $notes = [];
        $stage = null;

        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^#+\s*(.+)$/u', $line, $m)) {
                $stage = rtrim(trim($m[1]), ':') ?: null;

                continue;
            }
            if (preg_match('/^nota\s*(?:—|–|-|:)\s*(.*)$/iu', $line, $m)) {
                $notes[] = trim($m[1]);

                continue;
            }
            $line = preg_replace('/^(?:paso\s+)?\d+\s*[.):-]\s+/iu', '', $line);
            $minutes = null;
            if (preg_match('/\s*\((\d{1,4})\s*min(?:utos?)?\.?\)$/iu', $line, $m)) {
                $minutes = (int) $m[1];
                $line = trim(substr($line, 0, -strlen($m[0])));
            }
            if ($line !== '') {
                $steps[] = ['stage' => $stage, 'body' => $line, 'minutes' => $minutes];
            }
        }

        return [$steps, array_values(array_filter($notes))];
    }

    /**
     * Guarda la receta validada: sus datos, y reemplaza ingredientes, pasos y etiquetas.
     *
     * @param  array{recipe: array<string, mixed>, ingredients: array<int, array<string, mixed>>, steps: array<int, array<string, mixed>>, tags: array<int, string>}  $data
     */
    public static function save(Recipe $recipe, array $data): Recipe
    {
        return DB::transaction(function () use ($recipe, $data) {
            $recipe->fill($data['recipe'])->save();
            $recipe->ingredients()->delete();
            $recipe->ingredients()->createMany($data['ingredients']);
            $recipe->steps()->delete();
            $recipe->steps()->createMany($data['steps']);
            $recipe->tags()->sync(Tag::idsFor($data['tags']));

            return $recipe->unsetRelation('ingredients')->unsetRelation('steps')->unsetRelation('tags');
        });
    }

    /**
     * Lo que ofrece el formulario: los artículos (con su dimensión y conversiones, para
     * mostrar solo las unidades que se pueden usar), las recetas que pueden ser sub-receta
     * de esta (no ella misma ni las que ya la usan) y las unidades de ingrediente.
     *
     * @return array{products: array<int, array<string, mixed>>, recipes: array<int, array<string, mixed>>, units: array<string, array<string, mixed>>}
     */
    public static function formOptions(Recipe $recipe): array
    {
        $blocked = $recipe->exists ? RecipeIngredient::where('subrecipe_id', $recipe->id)->pluck('recipe_id')->all() : [];
        $subrecipes = Recipe::when($recipe->exists, fn ($q) => $q->where('id', '!=', $recipe->id))->orderBy('name')->get()
            ->reject(fn (Recipe $r) => $recipe->exists && (in_array($r->id, $blocked, true) || in_array($recipe->id, Recipe::descendantIds($r->id), true)));

        return [
            'products' => Product::orderBy('name')->get()->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->fullName(),
                'unit' => $p->unit->value,
                'dimension' => $p->unit->dimension(),
                'piece' => $p->piece_weight,
                'cup' => $p->cup_grams,
            ])->all(),
            'recipes' => $subrecipes->map(fn (Recipe $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'servings' => $r->servings,
                'yield' => $r->yieldDimension(),
                'yieldLabel' => $r->yieldLabel(),
            ])->values()->all(),
            'units' => IngredientUnit::meta(),
        ];
    }

    /**
     * La receta como entrada de validate() (para editar solo algunos campos por MCP).
     *
     * @return array<string, mixed>
     */
    public static function toInput(Recipe $recipe): array
    {
        $recipe->loadMissing(['ingredients', 'steps', 'tags']);

        return [
            ...$recipe->only(['name', 'category', 'servings', 'yield_qty', 'yield_unit', 'minutes', 'rating', 'source_url', 'notes']),
            'tags' => $recipe->tags->pluck('name')->all(),
            'ingredients' => $recipe->ingredients->map(fn (RecipeIngredient $i) => [
                'product_id' => $i->product_id,
                'subrecipe_id' => $i->subrecipe_id,
                'name' => $i->name,
                'quantity' => $i->quantity,
                'unit' => $i->unit?->value,
                'note' => $i->note,
                'section' => $i->section,
            ])->all(),
            'steps' => $recipe->steps->map(fn (RecipeStep $s) => $s->only(['stage', 'body', 'minutes']))->all(),
        ];
    }
}
