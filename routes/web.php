<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PriceController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductLinkController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ShoppingListController;
use App\Http\Controllers\ShoppingListItemController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');

Route::resource('providers', ProviderController::class)->except(['create', 'show']);
// Antes del resource para que "lookup" no se tome como {product}
Route::get('products/lookup', [ProductController::class, 'lookup'])->name('products.lookup');
Route::resource('products', ProductController::class);
Route::put('products/{product}/links', [ProductLinkController::class, 'update'])->name('products.links.update');
Route::post('products/{product}/prices/fetch', [ProductLinkController::class, 'fetch'])->name('products.prices.fetch');
Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
Route::resource('prices', PriceController::class)->except(['show']);

Route::resource('lists', ShoppingListController::class)->parameters(['lists' => 'list']);
Route::scopeBindings()->group(function () {
    Route::post('lists/{list}/items', [ShoppingListItemController::class, 'store'])->name('lists.items.store');
    Route::patch('lists/{list}/items/{item}', [ShoppingListItemController::class, 'update'])->name('lists.items.update');
    Route::delete('lists/{list}/items/{item}', [ShoppingListItemController::class, 'destroy'])->name('lists.items.destroy');
    Route::post('lists/{list}/items/{item}/prices', [ShoppingListItemController::class, 'savePrice'])->name('lists.items.prices.store');
    Route::post('lists/{list}/items/{item}/prices/fetch', [ShoppingListItemController::class, 'fetchPrices'])->name('lists.items.prices.fetch');
    Route::post('lists/{list}/items/{item}/links', [ShoppingListItemController::class, 'saveLink'])->name('lists.items.links.store');
    Route::delete('lists/{list}/items/{item}/prices/{price}', [ShoppingListItemController::class, 'destroyPrice'])
        ->withoutScopedBindings()->name('lists.items.prices.destroy');
});

Route::prefix('reports')->name('reports.')->controller(ReportController::class)->group(function () {
    Route::get('inflation', 'inflation')->name('inflation');
    Route::get('changes', 'changes')->name('changes');
    Route::get('spending', 'spending')->name('spending');
});
