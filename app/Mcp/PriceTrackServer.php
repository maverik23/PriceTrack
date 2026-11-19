<?php

namespace App\Mcp;

use App\Mcp\Tools\AddIngredientsToList;
use App\Mcp\Tools\AddListItem;
use App\Mcp\Tools\CompleteTask;
use App\Mcp\Tools\CreateProduct;
use App\Mcp\Tools\CreateRecipe;
use App\Mcp\Tools\CreateShoppingList;
use App\Mcp\Tools\CreateTask;
use App\Mcp\Tools\DeleteTask;
use App\Mcp\Tools\FetchOnlinePrices;
use App\Mcp\Tools\GetFinances;
use App\Mcp\Tools\GetMenu;
use App\Mcp\Tools\GetPriceReport;
use App\Mcp\Tools\GetProduct;
use App\Mcp\Tools\GetRecipe;
use App\Mcp\Tools\GetShoppingList;
use App\Mcp\Tools\GetSpendingReport;
use App\Mcp\Tools\GetSummary;
use App\Mcp\Tools\ListAssets;
use App\Mcp\Tools\ListProviders;
use App\Mcp\Tools\ListRecipes;
use App\Mcp\Tools\ListShoppingLists;
use App\Mcp\Tools\ListTasks;
use App\Mcp\Tools\MarkPaymentPaid;
use App\Mcp\Tools\PlanMeal;
use App\Mcp\Tools\RecordPrice;
use App\Mcp\Tools\RemoveListItem;
use App\Mcp\Tools\RemoveMeal;
use App\Mcp\Tools\SearchProducts;
use App\Mcp\Tools\UpdateListItem;
use App\Mcp\Tools\UpdateTask;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Servidor MCP de PriceTrack: lo que un asistente de IA puede consultar y hacer en la app.
 * Las herramientas llaman a los mismos modelos y servicios que las pantallas.
 */
#[Name('PriceTrack')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    PriceTrack es la app del hogar de quien te habla: comparador de precios del súper y listas de compras, recetas y menú semanal, tareas, bienes con su mantenimiento, y finanzas (inversiones, deudas, pagos recurrentes y patrimonio). Todo está en español de México, los montos en pesos (MXN) y las fechas en AAAA-MM-DD.

    - Empieza por `get-summary` si no sabes qué hay; las demás herramientas trabajan con los `id` que devuelven las de consulta.
    - Artículos: cada uno tiene una presentación habitual (size + unit). "El más barato" es el menor precio **por unidad** ($/L, $/kg, $/pza) entre el último precio de cada tienda; `latest_prices` ya viene en ese orden.
    - Listas: `quantity` es lo que se necesita, en presentaciones habituales. Lo que cuesta en una tienda son los paquetes completos que cubren esa cantidad (o lo justo si es a granel): la tienda más barata por unidad no siempre es donde sale más barato llevarse lo que se necesita (`cost_for_need`).
    - El gasto solo cuenta los artículos marcados como comprados en una tienda, en la fecha de su lista.
    - Busca antes de crear (artículos, recetas) para no duplicar, y confirma con la persona antes de borrar algo.
    MARKDOWN)]
class PriceTrackServer extends Server
{
    /** Todas las herramientas caben en una sola página de tools/list. */
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        GetSummary::class,

        // Súper
        SearchProducts::class,
        GetProduct::class,
        CreateProduct::class,
        ListProviders::class,
        RecordPrice::class,
        FetchOnlinePrices::class,
        ListShoppingLists::class,
        GetShoppingList::class,
        CreateShoppingList::class,
        AddListItem::class,
        UpdateListItem::class,
        RemoveListItem::class,
        GetSpendingReport::class,
        GetPriceReport::class,

        // Cocina
        ListRecipes::class,
        GetRecipe::class,
        CreateRecipe::class,
        GetMenu::class,
        PlanMeal::class,
        RemoveMeal::class,
        AddIngredientsToList::class,

        // Hogar
        ListTasks::class,
        CreateTask::class,
        UpdateTask::class,
        CompleteTask::class,
        DeleteTask::class,
        ListAssets::class,

        // Finanzas
        GetFinances::class,
        MarkPaymentPaid::class,
    ];
}
