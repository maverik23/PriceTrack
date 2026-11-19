<?php

namespace App\Mcp;

use App\Mcp\Tools\AddIngredientsToList;
use App\Mcp\Tools\AddListItem;
use App\Mcp\Tools\CompleteTask;
use App\Mcp\Tools\CorrectFinanceRecord;
use App\Mcp\Tools\CreateProduct;
use App\Mcp\Tools\CreateRecipe;
use App\Mcp\Tools\CreateShoppingList;
use App\Mcp\Tools\CreateTask;
use App\Mcp\Tools\DeleteAssetRecord;
use App\Mcp\Tools\DeleteFinanceRecord;
use App\Mcp\Tools\DeleteTask;
use App\Mcp\Tools\DeleteTaskList;
use App\Mcp\Tools\FetchOnlinePrices;
use App\Mcp\Tools\GetAsset;
use App\Mcp\Tools\GetFinanceRecords;
use App\Mcp\Tools\GetFinances;
use App\Mcp\Tools\GetHelp;
use App\Mcp\Tools\GetIncomes;
use App\Mcp\Tools\GetMenu;
use App\Mcp\Tools\GetPantry;
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
use App\Mcp\Tools\RecordAsset;
use App\Mcp\Tools\RecordDebt;
use App\Mcp\Tools\RecordIncome;
use App\Mcp\Tools\RecordInvestment;
use App\Mcp\Tools\RecordPrice;
use App\Mcp\Tools\RemoveListItem;
use App\Mcp\Tools\RemoveMeal;
use App\Mcp\Tools\SaveAsset;
use App\Mcp\Tools\SaveDebt;
use App\Mcp\Tools\SaveIncomeSource;
use App\Mcp\Tools\SaveInvestmentAccount;
use App\Mcp\Tools\SaveMaintenancePlan;
use App\Mcp\Tools\SaveRecurringPayment;
use App\Mcp\Tools\SaveTaskList;
use App\Mcp\Tools\SearchProducts;
use App\Mcp\Tools\SetMenuNote;
use App\Mcp\Tools\SetStoreBranch;
use App\Mcp\Tools\UpdateListItem;
use App\Mcp\Tools\UpdatePantry;
use App\Mcp\Tools\UpdatePayment;
use App\Mcp\Tools\UpdateRecipe;
use App\Mcp\Tools\UpdateTask;
use App\Mcp\Tools\UseRecipeAsIngredient;
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
    PriceTrack es la app del hogar de quien te habla: comparador de precios del súper y listas de compras, recetas y menú semanal, tareas, bienes con su mantenimiento, y finanzas (ingresos, pagos recurrentes con lecturas de medidor, deudas, inversiones y patrimonio). Casi todo se puede consultar, crear, corregir y borrar. Todo está en español de México, los montos en pesos (MXN) y las fechas en AAAA-MM-DD.

    - `get-help` explica qué puedes hacer en cada sección y con qué herramienta (y cómo se conecta este servidor). Empieza por `get-summary` si no sabes qué hay; las demás herramientas trabajan con los `id` que devuelven las de consulta.
    - Artículos: cada uno tiene una presentación habitual (size + unit). "El más barato" es el menor precio **por unidad** ($/L, $/kg, $/pza) entre el último precio de cada tienda; `latest_prices` ya viene en ese orden.
    - Listas: `quantity` es lo que se necesita, en presentaciones habituales. Lo que cuesta en una tienda son los paquetes completos que cubren esa cantidad (o lo justo si es a granel): la tienda más barata por unidad no siempre es donde sale más barato llevarse lo que se necesita (`cost_for_need`).
    - El gasto solo cuenta los artículos marcados como comprados en una tienda, en la fecha de su lista.
    - Despensa: lo que está en casa (`pantry.status = stocked`) no se manda a la lista desde recetas o menú; si dice cuánto queda, solo se manda lo que falta. Revisa con `add-ingredients-to-list` y `preview: true` antes de mandar.
    - Recetas: un ingrediente puede ser otra receta (sub-receta) en lugar de un artículo "Casero"; los pasos van por etapas y los ingredientes por secciones. Corrige con `update-recipe` (lee antes con `get-recipe`).
    - Busca antes de crear (artículos, recetas) para no duplicar, y confirma con la persona antes de borrar algo.
    MARKDOWN)]
class PriceTrackServer extends Server
{
    /** Todas las herramientas caben en una sola página de tools/list. */
    public int $defaultPaginationLength = 100;

    public int $maxPaginationLength = 100;

    /**
     * Herramientas por sección (de aquí sale la guía de get-help y de la pantalla "Asistente (MCP)").
     *
     * @var array<string, array{0: string, 1: list<class-string>}>
     */
    public const SECTIONS = [
        'general' => ['General', [
            GetHelp::class,
            GetSummary::class,
        ]],
        'super' => ['Súper', [
            SearchProducts::class,
            GetProduct::class,
            CreateProduct::class,
            ListProviders::class,
            RecordPrice::class,
            FetchOnlinePrices::class,
            SetStoreBranch::class,
            ListShoppingLists::class,
            GetShoppingList::class,
            CreateShoppingList::class,
            AddListItem::class,
            UpdateListItem::class,
            RemoveListItem::class,
            GetSpendingReport::class,
            GetPriceReport::class,
        ]],
        'cocina' => ['Cocina', [
            ListRecipes::class,
            GetRecipe::class,
            CreateRecipe::class,
            UpdateRecipe::class,
            UseRecipeAsIngredient::class,
            GetMenu::class,
            PlanMeal::class,
            SetMenuNote::class,
            RemoveMeal::class,
            AddIngredientsToList::class,
            GetPantry::class,
            UpdatePantry::class,
        ]],
        'hogar' => ['Hogar', [
            ListTasks::class,
            CreateTask::class,
            UpdateTask::class,
            CompleteTask::class,
            DeleteTask::class,
            SaveTaskList::class,
            DeleteTaskList::class,
            ListAssets::class,
            GetAsset::class,
            SaveAsset::class,
            RecordAsset::class,
            SaveMaintenancePlan::class,
            DeleteAssetRecord::class,
        ]],
        'finanzas' => ['Finanzas', [
            GetFinances::class,
            MarkPaymentPaid::class,
            UpdatePayment::class,
            SaveRecurringPayment::class,
            GetIncomes::class,
            RecordIncome::class,
            SaveIncomeSource::class,
            SaveInvestmentAccount::class,
            RecordInvestment::class,
            SaveDebt::class,
            RecordDebt::class,
            GetFinanceRecords::class,
            CorrectFinanceRecord::class,
            DeleteFinanceRecord::class,
        ]],
    ];

    protected array $tools = [
        ...self::SECTIONS['general'][1],
        ...self::SECTIONS['super'][1],
        ...self::SECTIONS['cocina'][1],
        ...self::SECTIONS['hogar'][1],
        ...self::SECTIONS['finanzas'][1],
    ];
}
