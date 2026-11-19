<?php

use App\Http\Controllers\Api;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\PantryController;
use App\Http\Controllers\PriceRunController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductLinkController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ShoppingListController;
use App\Http\Controllers\ShoppingListItemController;
use App\Http\Middleware\ApiToken;
use Illuminate\Support\Facades\Route;

// API REST de la app móvil (mobile/), en /api/v1 (bootstrap/app.php). Sin sesión ni CSRF;
// API_ENABLED=false la quita y API_TOKEN exige "Authorization: Bearer" (middleware ApiToken).
// Lo que la web ya contesta en JSON (la hoja de la lista, la despensa, los precios en línea)
// usa sus mismos controladores; lo demás está en App\Http\Controllers\Api.
if (! config('services.api.enabled')) {
    return;
}

// Con nombre propio (api.*): los route('lists.show') de la web siguen siendo los de la web
Route::middleware(ApiToken::class)->name('api.')->group(function () {
    // Para probar la conexión desde la app
    Route::get('ping', fn () => response()->json(['app' => 'PriceTrack', 'api' => 1, 'time' => now()->toIso8601String()]));
    Route::get('dashboard', Api\DashboardController::class);

    // Súper: tiendas
    Route::apiResource('providers', Api\ProviderController::class)->except(['show']);
    Route::put('settings/postal-code', [Api\ProviderController::class, 'postalCode']);
    Route::get('settings/branches', [SettingController::class, 'branches']);
    Route::put('settings/branch', [SettingController::class, 'saveBranch']);

    // Súper: artículos (lo fijo antes del recurso para que no se tome como {product})
    Route::get('products/lookup', [ProductController::class, 'lookup']);
    Route::get('products/catalog', [Api\ProductController::class, 'catalog']);
    Route::get('products/options', [Api\ProductController::class, 'options']);
    Route::apiResource('products', Api\ProductController::class);
    Route::post('products/{product}/attachments', [Api\ProductController::class, 'attachments']);
    Route::post('products/{product}/photo/fetch', [ProductController::class, 'fetchPhoto']);
    Route::put('products/{product}/links/{provider}', [Api\ProductController::class, 'saveLink']);
    Route::post('products/{product}/pantry', [PantryController::class, 'update']);
    Route::post('products/{product}/pantry/list', [PantryController::class, 'addToList']);
    Route::get('pantry', [Api\PantryController::class, 'index']);

    // Súper: precios y consulta en línea
    Route::apiResource('prices', Api\PriceController::class)->except(['show']);
    Route::post('product-links/{link}/fetch', [ProductLinkController::class, 'fetchLink']);
    Route::post('price-runs', [PriceRunController::class, 'store']);
    Route::get('price-runs/{run}', [PriceRunController::class, 'show']);
    Route::post('price-runs/{run}/cancel', [PriceRunController::class, 'cancel']);
    Route::post('price-runs/{run}/resume', [PriceRunController::class, 'resume']);

    // Súper: listas de compra y su hoja
    Route::apiResource('lists', Api\ShoppingListController::class)->parameters(['lists' => 'list']);
    Route::post('lists/{list}/done', [Api\ShoppingListController::class, 'done']);
    Route::put('lists/{list}/providers', [ShoppingListController::class, 'providerColumns']);
    Route::scopeBindings()->group(function () {
        Route::post('lists/{list}/items', [ShoppingListItemController::class, 'store']);
        Route::patch('lists/{list}/items/{item}', [ShoppingListItemController::class, 'update']);
        Route::delete('lists/{list}/items/{item}', [ShoppingListItemController::class, 'destroy']);
        Route::put('lists/{list}/tickets/{provider}', [ShoppingListItemController::class, 'saveTicket'])->withoutScopedBindings();
        Route::post('lists/{list}/items/{item}/prices', [ShoppingListItemController::class, 'savePrice']);
        Route::post('lists/{list}/items/{item}/prices/fetch', [ShoppingListItemController::class, 'fetchPrices']);
        Route::post('lists/{list}/items/{item}/links', [ShoppingListItemController::class, 'saveLink']);
        Route::delete('lists/{list}/items/{item}/prices/{price}', [ShoppingListItemController::class, 'destroyPrice'])->withoutScopedBindings();
    });

    // Cocina: recetas (lo fijo antes del recurso para que no se tome como {recipe})
    Route::get('recipes/options', [Api\RecipeController::class, 'options']);
    Route::apiResource('recipes', Api\RecipeController::class);
    Route::post('recipes/{recipe}/rating', [RecipeController::class, 'rate']);
    Route::post('recipes/{recipe}/attachments', [Api\RecipeController::class, 'attachments']);
    Route::get('recipes/{recipe}/shop', [Api\RecipeController::class, 'shopReview']);
    Route::post('recipes/{recipe}/shop', [Api\RecipeController::class, 'shop']);
    Route::post('products/{product}/subrecipe', [Api\ProductController::class, 'subrecipe']);

    // Cocina: menú semanal
    Route::get('menu', [Api\MenuController::class, 'index']);
    Route::post('menu', [Api\MenuController::class, 'store']);
    Route::get('menu/shop', [Api\MenuController::class, 'shopReview']);
    Route::post('menu/shop', [Api\MenuController::class, 'shop']);
    Route::put('menu/notes/{date}', [MenuController::class, 'note'])->where('date', '\d{4}-\d{2}-\d{2}');
    Route::delete('menu/{entry}', [Api\MenuController::class, 'destroy']);

    // Fotos y documentos: el archivo (?thumb=1 la miniatura) y quitarlo
    Route::get('attachments/{attachment}', [AttachmentController::class, 'show']);
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy']);
});
