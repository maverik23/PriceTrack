<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetRecordController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\InvestmentController;
use App\Http\Controllers\InvestmentRecordController;
use App\Http\Controllers\MaintenancePlanController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\NetWorthController;
use App\Http\Controllers\PantryController;
use App\Http\Controllers\PriceController;
use App\Http\Controllers\PriceRunController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductLinkController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\RecurringPaymentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ShoppingListController;
use App\Http\Controllers\ShoppingListItemController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskListController;
use App\Mcp\Guide;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');

Route::resource('providers', ProviderController::class)->except(['create', 'show']);
// Antes del resource para que "lookup" no se tome como {product}
Route::get('products/lookup', [ProductController::class, 'lookup'])->name('products.lookup');
Route::post('price-runs', [PriceRunController::class, 'store'])->name('price-runs.store');
Route::get('price-runs/{run}', [PriceRunController::class, 'show'])->name('price-runs.show');
Route::post('price-runs/{run}/cancel', [PriceRunController::class, 'cancel'])->name('price-runs.cancel');
Route::post('price-runs/{run}/resume', [PriceRunController::class, 'resume'])->name('price-runs.resume');
Route::get('products/online-links', [ProductLinkController::class, 'plan'])->name('products.online-links');
Route::resource('products', ProductController::class);
Route::put('products/{product}/links', [ProductLinkController::class, 'update'])->name('products.links.update');
Route::post('products/{product}/photo/fetch', [ProductController::class, 'fetchPhoto'])->name('products.photo.fetch');
Route::post('products/{product}/prices/fetch', [ProductLinkController::class, 'fetch'])->name('products.prices.fetch');
Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
Route::get('settings/branches', [SettingController::class, 'branches'])->name('settings.branches');
Route::put('settings/branch', [SettingController::class, 'saveBranch'])->name('settings.branch');
Route::post('product-links/{link}/fetch', [ProductLinkController::class, 'fetchLink'])->name('product-links.fetch');
Route::resource('prices', PriceController::class)->except(['show']);

Route::put('lists/{list}/providers', [ShoppingListController::class, 'providerColumns'])->name('lists.providers');
Route::post('lists/{list}/done', [ShoppingListController::class, 'toggleDone'])->name('lists.done');
Route::resource('lists', ShoppingListController::class)->parameters(['lists' => 'list']);
Route::scopeBindings()->group(function () {
    Route::post('lists/{list}/items', [ShoppingListItemController::class, 'store'])->name('lists.items.store');
    Route::patch('lists/{list}/items/{item}', [ShoppingListItemController::class, 'update'])->name('lists.items.update');
    Route::delete('lists/{list}/items/{item}', [ShoppingListItemController::class, 'destroy'])->name('lists.items.destroy');
    Route::put('lists/{list}/tickets/{provider}', [ShoppingListItemController::class, 'saveTicket'])->withoutScopedBindings()->name('lists.tickets.update');
    Route::post('lists/{list}/items/{item}/prices', [ShoppingListItemController::class, 'savePrice'])->name('lists.items.prices.store');
    Route::post('lists/{list}/items/{item}/prices/fetch', [ShoppingListItemController::class, 'fetchPrices'])->name('lists.items.prices.fetch');
    Route::post('lists/{list}/items/{item}/links', [ShoppingListItemController::class, 'saveLink'])->name('lists.items.links.store');
    Route::delete('lists/{list}/items/{item}/prices/{price}', [ShoppingListItemController::class, 'destroyPrice'])
        ->withoutScopedBindings()->name('lists.items.prices.destroy');
});

// Cocina: recetas y menú semanal
Route::resource('recipes', RecipeController::class);
Route::post('recipes/{recipe}/rating', [RecipeController::class, 'rate'])->name('recipes.rate');
Route::get('recipes/{recipe}/shop', [RecipeController::class, 'shopReview'])->name('recipes.shop.review');
Route::post('recipes/{recipe}/shop', [RecipeController::class, 'shop'])->name('recipes.shop');
Route::get('pantry', [PantryController::class, 'index'])->name('pantry.index');
Route::post('products/{product}/subrecipe', [ProductController::class, 'useRecipe'])->name('products.subrecipe');
Route::post('products/{product}/pantry', [PantryController::class, 'update'])->name('products.pantry');
Route::post('products/{product}/pantry/list', [PantryController::class, 'addToList'])->name('products.pantry.list');
Route::get('menu', [MenuController::class, 'index'])->name('menu.index');
Route::post('menu', [MenuController::class, 'store'])->name('menu.store');
Route::get('menu/shop', [MenuController::class, 'shopReview'])->name('menu.shop.review');
Route::post('menu/shop', [MenuController::class, 'shop'])->name('menu.shop');
Route::put('menu/notes/{date}', [MenuController::class, 'note'])->name('menu.note')->where('date', '\d{4}-\d{2}-\d{2}');
Route::delete('menu/{entry}', [MenuController::class, 'destroy'])->name('menu.destroy');

// Fotos y documentos (bienes y recetas)
Route::post('assets/{asset}/attachments', [AttachmentController::class, 'storeForAsset'])->name('assets.attachments.store');
Route::post('recipes/{recipe}/attachments', [AttachmentController::class, 'storeForRecipe'])->name('recipes.attachments.store');
Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
Route::patch('attachments/{attachment}', [AttachmentController::class, 'update'])->name('attachments.update');
Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

// Hogar: tareas
Route::resource('tasks', TaskController::class)->only(['index', 'store', 'edit', 'update', 'destroy']);
Route::post('tasks/{task}/toggle', [TaskController::class, 'toggle'])->name('tasks.toggle');
Route::resource('task-lists', TaskListController::class)->only(['store', 'update', 'destroy']);

// Hogar: bienes, su valor y su mantenimiento
Route::resource('assets', AssetController::class);
Route::scopeBindings()->prefix('assets/{asset}')->name('assets.')->group(function () {
    Route::post('valuations', [AssetRecordController::class, 'storeValuation'])->name('valuations.store');
    Route::delete('valuations/{valuation}', [AssetRecordController::class, 'destroyValuation'])->name('valuations.destroy');
    Route::post('readings', [AssetRecordController::class, 'storeReading'])->name('readings.store');
    Route::delete('readings/{meterReading}', [AssetRecordController::class, 'destroyReading'])->name('readings.destroy');
    Route::post('logs', [AssetRecordController::class, 'storeLog'])->name('logs.store');
    Route::delete('logs/{maintenanceLog}', [AssetRecordController::class, 'destroyLog'])->name('logs.destroy');
    Route::resource('plans', MaintenancePlanController::class)->only(['store', 'edit', 'update', 'destroy'])
        ->parameters(['plans' => 'maintenancePlan']);
});

// Finanzas: inversiones y patrimonio
Route::resource('investments', InvestmentController::class);
Route::scopeBindings()->prefix('investments/{investment}')->name('investments.')->controller(InvestmentRecordController::class)->group(function () {
    Route::post('transactions', 'storeTransaction')->name('transactions.store');
    Route::delete('transactions/{transaction}', 'destroyTransaction')->name('transactions.destroy');
    Route::post('valuations', 'storeValuation')->name('valuations.store');
    Route::delete('valuations/{valuation}', 'destroyValuation')->name('valuations.destroy');
});
Route::resource('debts', DebtController::class);
Route::scopeBindings()->prefix('debts/{debt}')->name('debts.')->controller(DebtController::class)->group(function () {
    Route::post('balances', 'storeBalance')->name('balances.store');
    Route::delete('balances/{balance}', 'destroyBalance')->name('balances.destroy');
    Route::post('payments', 'storePayment')->name('payments.store');
    Route::delete('payments/{payment}', 'destroyPayment')->name('payments.destroy');
});
// Cómo usar PriceTrack desde un asistente de IA (MCP): conexión y herramientas
Route::get('assistant', fn () => view('assistant', [
    'sections' => Guide::sections(),
    'counts' => Guide::counts(),
    'enabled' => (bool) config('services.mcp.enabled'),
    'hasToken' => filled(config('services.mcp.token')),
]))->name('assistant');
Route::get('incomes', [IncomeController::class, 'index'])->name('incomes.index');
Route::post('incomes', [IncomeController::class, 'store'])->name('incomes.store');
Route::delete('incomes/{income}', [IncomeController::class, 'destroy'])->name('incomes.destroy');
Route::post('income-sources', [IncomeController::class, 'storeSource'])->name('incomes.sources.store');
Route::get('income-sources/{incomeSource}/edit', [IncomeController::class, 'editSource'])->name('incomes.sources.edit');
Route::put('income-sources/{incomeSource}', [IncomeController::class, 'updateSource'])->name('incomes.sources.update');
Route::delete('income-sources/{incomeSource}', [IncomeController::class, 'destroySource'])->name('incomes.sources.destroy');
Route::post('income-sources/{incomeSource}/receive', [IncomeController::class, 'receive'])->name('incomes.sources.receive');
Route::resource('recurring', RecurringPaymentController::class)->except(['create', 'show'])
    ->parameters(['recurring' => 'recurringPayment']);
Route::post('recurring/{recurringPayment}/pay', [RecurringPaymentController::class, 'pay'])->name('recurring.pay');
Route::put('paid/{payment}', [RecurringPaymentController::class, 'updatePayment'])->name('paid.update');
Route::delete('paid/{payment}', [RecurringPaymentController::class, 'destroyPayment'])->name('paid.destroy');
Route::get('net-worth', NetWorthController::class)->name('net-worth');

Route::prefix('reports')->name('reports.')->controller(ReportController::class)->group(function () {
    Route::get('inflation', 'inflation')->name('inflation');
    Route::get('changes', 'changes')->name('changes');
    Route::get('spending', 'spending')->name('spending');
    Route::get('assets', 'assets')->name('assets');
});
