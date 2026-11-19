<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\MealPlanEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Quita una comida del menú semanal.')]
#[IsDestructive]
class RemoveMeal extends Tool
{
    public function handle(Request $request): Response
    {
        $entry = $this->find(MealPlanEntry::class, $request->get('entry_id'), 'una comida del menú', ['recipe']);
        $entry->delete();

        return $this->json(['removed' => $entry->label(), 'date' => $entry->planned_on->toDateString()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['entry_id' => $schema->integer()->description('`entry_id` de la comida (de get-menu).')->required()];
    }
}
