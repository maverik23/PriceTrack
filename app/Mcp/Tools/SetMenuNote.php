<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\MealDayNote;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Escribe la nota de un día del menú (p. ej. "Mealprep: cocer arroz y pollo para la semana"); se ve en la semana del menú. Un texto vacío la borra. Hay una nota por día: la nueva reemplaza la anterior.')]
class SetMenuNote extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['date' => 'día', 'note' => 'nota']);

        $note = MealDayNote::put($this->date($data['date'])->toDateString(), $data['note'] ?? null);

        return $this->json(['date' => $this->date($data['date'])->toDateString(), 'note' => $note?->body]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description('Día, AAAA-MM-DD.')->required(),
            'note' => $schema->string()->description('La nota; vacía para borrarla.'),
        ];
    }
}
