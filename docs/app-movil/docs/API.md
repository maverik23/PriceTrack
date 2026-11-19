# API de PriceTrack para la app (`/api/v1`)

Vive en el servidor (`maverik23/PriceTrack`): `routes/api.php`, `App\Http\Controllers\Api\*`, `App\Api\Present`, `App\Api\ListSheet`; tests en `tests/Feature/ApiTest.php`. Lo que la web ya contestaba en JSON (hoja de la lista, despensa, precios en línea) usa los mismos controladores de la web.

> **Estado:** Inicio y Súper **ya existen y están probados** (rama `claude/friendly-mendel-2k6ot2` del servidor; confirma que esté en `main` y desplegada antes de probar contra un servidor real). Cocina, Hogar y Finanzas **faltan**: ver «Por construir».

## Generalidades

- Base: `{servidor}/api/v1/`. Siempre responde JSON (aunque no se mande `Accept`).
- **Token**: si el servidor define `API_TOKEN`, cada petición lleva `Authorization: Bearer <token>`; si no, responde `401 {"message": …}`. `API_ENABLED=false` quita la API (todo da 404).
- Probar conexión: `GET ping` → `{"app": "PriceTrack", "api": 1, "time": "…"}`. La app debe comprobar `app == "PriceTrack"`.
- **Errores**: `422 {"message", "errors": {"campo": ["texto en español"]}}`; `404 {"message"}`; `409 {"message", "needs_branch": {…}}` al consultar precios en línea de una tienda que pide sucursal (Chedraui) sin haberla elegido.
- **Paginación** (listas): `?page=` y `?per_page=` (1 a 200, 50 por defecto) → `{"data": […], "meta": {"page", "per_page", "last_page", "total"}}` más campos propios de cada lista.
- Fechas `AAAA-MM-DD`. Dinero y cantidades como números. Unidades: `ml`, `l`, `g`, `kg`, `item` (se muestran ml, L, g, kg, pza).
- Las direcciones de fotos son **relativas a la API** (`attachments/12?thumb=1`) y se piden con el mismo token.
- Al cambiar algo «solo cambia lo que se manda» (`PUT` parcial) en artículos, tiendas, precios y listas.

Ejemplos reales (recortados a 2 elementos por arreglo) en `docs/ejemplos/`.

## Inicio

| Método | Ruta | Devuelve |
|---|---|---|
| GET | `dashboard` | `data`: `today`, `counts`, `groceries` (gasto del mes y del pasado, promedio, inflación, `recent_lists`, `price_changes`), `kitchen` (`today`, `week_meals`, `week_cost`), `home` (`due_tasks`, `overdue`, `upcoming`, mantenimiento, garantías), `finance` (`net_worth`, cambio en el mes, `due_payments`). Ejemplo: `dashboard.json` |

## Súper: tiendas

| Método | Ruta | Notas |
|---|---|---|
| GET | `providers?q=` | Paginado. Cada una: `id, name, notes, groceries, chain, chain_name, branch, branch_name, branch_label, prices_count, last_price_on`. Además `postal_code` y `chains` (`id, name, list`: si la sucursal se elige de una lista o se escribe) |
| POST | `providers` | `name` (único), `notes`, `groceries` (si no se manda se adivina por el nombre), `chain`, `branch`, `branch_name` → `201 {data, message}` |
| PUT | `providers/{id}` | Igual, parcial |
| DELETE | `providers/{id}` | Borra también sus precios |
| PUT | `settings/postal-code` | `postal_code` (5 dígitos o vacío) → `{postal_code, message}`. Cambiarlo borra las sucursales elegidas de una lista |
| GET | `settings/branches?chain=&postal_code=&provider_id=` | `{branches: [{id, label}], listable, selected}` |
| PUT | `settings/branch` | `provider_id, chain?, postal_code, branch, branch_name` → `{ok, label}` |

## Súper: artículos

| Método | Ruta | Notas |
|---|---|---|
| GET | `products?q=&category=&detail=` | Paginado. `q` con un código de barras da solo ese artículo (y `barcode` en la respuesta). Cada uno: `id, name, brand, category, presentation, size, unit, unit_label, barcode, notes, pantry {status, remaining}, photo, latest_prices[]` (último precio por tienda, del más barato por unidad al más caro). Con `detail=1` cada uno trae la ficha completa (para la copia sin conexión). Ejemplo: `products_index.json` |
| GET | `products/catalog` | Todos, en corto: `id, name, brand, barcode, barcodes[], category, size, unit, label`. Para buscar y agregar a una lista (también sin conexión) y para reconocer un código escaneado |
| GET | `products/options` | `units` (tabla de conversiones), `unit_names`, `categories`, `brands`, `providers`. Para los formularios |
| GET | `products/lookup?barcode=` | `{barcode, product, suggestion, error}`: busca en lo guardado y, si no, en internet (`suggestion`: nombre, marca, categoría, size/unit, `image_url`, `nutrition`) |
| GET | `products/{id}` | `data`: lo de la lista más `piece_weight, cup_grams, kcal, protein, carbs, fat, compare_label, savings, prices[]` (todo el historial, del más nuevo al más viejo), `links[]` (su versión en cada tienda: `id, provider_id, provider, url, brand, barcode, sku, store_name, checked_at, error`), `attachments[]`. Ejemplo: `products_show.json` |
| POST | `products` | `name, size, unit` obligatorios; `brand, category, barcode, notes, piece_weight, cup_grams, kcal, protein, carbs, fat` → `201` con la ficha |
| PUT | `products/{id}` | Parcial. No deja cambiar a una unidad de otra dimensión si ya hay precios |
| DELETE | `products/{id}` | Borra también su historial de precios |
| POST | `products/{id}/attachments` | **multipart**: `files[]` (fotos, ya reducidas a ≤ 2000 px), `thumbs[]` (miniatura de 480 px por cada una, mismo índice), `remove_attachments[]`, `attachment_order[]` (`a{id}` guardadas, `n{i}` nuevas; la primera foto es la portada) → la ficha |
| POST | `products/{id}/photo/fetch` | `link_id?`: trae la foto de la tienda o de Open Food Facts → `{ok, message, photo}` (422 si no se pudo) |
| PUT | `products/{id}/links/{provider}` | `url` (https), `brand`, `barcode`: parcial; si todo queda vacío se quita → la ficha |
| GET | `attachments/{id}` | El archivo (`?thumb=1` la miniatura si existe) |
| DELETE | `attachments/{id}` | `{message}` |

## Súper: despensa

| Método | Ruta | Notas |
|---|---|---|
| GET | `pantry` | `data[]` (todos los artículos): `id, name, brand, category, presentation, unit, pantry` (`stocked`, `out` o null), `qty` ("300 g" o null = suficiente), `updated, value, estimated, in_list`; además `lists` (pendientes donde agregar), `categories`, `counts {stocked, out}`, `value` (lo que vale la despensa). Ejemplo: `pantry.json` |
| POST | `products/{id}/pantry` | `have` (1/0), `qty?` ("300 g"), `forget?` (dejar de llevarlo) → `{row}` |
| POST | `products/{id}/pantry/list` | `list_id?` (sin ella, lista nueva) → `{message, list, row}`. No duplica si ya está pendiente |

## Súper: precios

| Método | Ruta | Notas |
|---|---|---|
| GET | `prices?q=&product_id=&provider_id=&from=&to=` | Paginado, del más nuevo al más viejo. Cada uno: `id, price_id, product_id, product, provider_id, provider, price, pack_qty, size, unit, bulk, presentation, unit_price` (por L/kg/pza), `unit_price_per, observed_on, notes, list_id, list`. Ejemplo: `prices_index.json` |
| POST | `prices` | `product_id, provider_id, price, size, unit, observed_on` (no futura); `pack_qty` (1), `bulk`, `notes`. La unidad debe ser de la dimensión del artículo |
| PUT | `prices/{id}` | Parcial |
| DELETE | `prices/{id}` | |
| POST | `product-links/{link}/fetch` | Consulta el precio en línea de un enlace. `list_id?` (lo registra con la fecha de la lista), `preview=1` (solo lo devuelve, sin guardar). → `{ok, message, provider_id, provider, product, price}`. Tarda segundos; puede dar 409 `needs_branch` |
| POST | `price-runs` | «Actualizar todos los precios» en el servidor. `skip_providers[]?` → `{run}` (`id, title, status, active, stalled, finished, cancelled, total, done, ok, failures[], current`). 422 si ningún artículo tiene dirección. Solo una a la vez (devuelve la que ya corre) |
| GET | `price-runs/{id}` | Avance (consultar cada 2 s) |
| POST | `price-runs/{id}/cancel` · `…/resume` | |

## Súper: listas de compra

| Método | Ruta | Notas |
|---|---|---|
| GET | `lists?status=pending\|done\|all&q=` | Paginado; por defecto solo pendientes. Cada una: `id, name, shopped_on, notes, done, completed_at, items, purchased, total_paid, estimated_total, bought_at[]`. Además `status` y `counts {pending, done, all}`. Ejemplo: `lists_index.json` |
| POST | `lists` | `name, shopped_on`; `notes`, `copy_from` (copia los artículos de otra lista, sin precios) → `201` con la hoja |
| GET | `lists/{id}` | **La hoja ya calculada** (abajo). Ejemplo: `lists_show.json` |
| PUT | `lists/{id}` | `name`, `shopped_on`, `notes` (parcial) → la hoja |
| DELETE | `lists/{id}` | Los precios capturados se conservan |
| POST | `lists/{id}/done` | `done` 1/0 (sin él, alterna) → `{data: resumen, message}` |
| PUT | `lists/{id}/providers` | `added[]`, `hidden[]` (ambos obligatorios, pueden ir vacíos): tiendas mostradas u ocultadas a mano |
| POST | `lists/{id}/items` | `product_id` + `quantity?`, **o** artículo nuevo: `name, size, unit` + `barcode?, brand?, category?`. → `201 {existing: false, row, product}`; si el artículo ya estaba, `200 {existing: true, …}` **sin cambiar su cantidad** (repetible sin daño) |
| PATCH | `lists/{id}/items/{item}` | `quantity`, `price_id` (un precio de ese artículo; null = desmarcar), `bought_qty` (unidades del precio elegido; null = lo que cubre lo pedido) → `{item}` |
| DELETE | `lists/{id}/items/{item}` | `{ok}` |
| POST | `lists/{id}/items/{item}/prices` | Capturar un precio en la hoja: `provider_id, price, size, unit`, `pack_qty?`, `bulk?`. Queda con la fecha de la lista (corrige el de ese día si ya había). Si la compra estaba en esa tienda pasa al precio nuevo → `{price, item}` |
| DELETE | `lists/{id}/items/{item}/prices/{price}` | Solo precios capturados en esa lista |
| POST | `lists/{id}/items/{item}/prices/fetch` | `provider_id?`: consulta en línea las tiendas con dirección → `{row, results[]}` |
| POST | `lists/{id}/items/{item}/links` | `provider_id, url`: guarda la dirección y consulta su precio |
| PUT | `lists/{id}/tickets/{provider}` | `total` (vacío lo quita): lo que de verdad se pagó en esa tienda → `{provider_id, ticket, total}` |

Las respuestas `row`, `item` y `price` de las rutas de renglones son las de la web (`ListGrid`); la app **no las usa para pintar**: después de cada cambio vuelve a pedir `GET lists/{id}`.

### La hoja (`GET lists/{id}` → `data`)

- `list`: `id, name, shopped_on, notes, done, price_date`.
- `provider_columns`: `{added[], hidden[]}`.
- `providers[]` (todas las tiendas): `id, name, visible` (tiene datos en la lista o se agregó a mano, y no se ocultó), `can_hide` (false si se compró algo ahí), `bought_count`, `bought_total` (suma de lo marcado), `ticket` (null = sin ticket), `paid` (el ticket o la suma), `basket {covered, total}` («si compras todo aquí»; null si no se ve), `best_basket`.
- `rows[]`: `id`, `product` (como en `catalog`, más `photo`), `quantity` (en presentaciones habituales), `need_base`, `need_label` ("690 ml"), `price_id` (null = pendiente), `bought_qty`, `bought_units`, `bought_label` ("3 × 345 ml"), `subtotal` (lo pagado), `estimated` (lo que costaría en la tienda más barata para esa cantidad), `best_buy {provider_id, cost}`, `selected` (el precio elegido), `cells[]`, `links {provider_id: {id, url}}`, `stores {provider_id: {brand, name, barcode}}`.
- `cells[]` (último precio de cada tienda **visible**): `id, provider_id, price, pack_qty, size, unit, bulk, presentation, base_qty, unit_price, observed_on, date_label, current` (capturado con la fecha de esta lista), `cost_for_need` (paquetes completos o lo justo a granel), `packs` (null a granel), `compare_price` + `compare_label` ($/L, $/kg, $/pza), `cheapest` (★: el más barato por unidad), `best_buy` (donde conviene llevarse lo pedido), `diff` (% más caro que el más barato), `bought`.
- `summary`: `items, purchased, total_paid, total_marked, total_estimated, real_savings` (ahorro contra la tienda más cara), `cheapest_total, possible_savings`.

## Por construir (en el servidor, antes de cada fase de la app)

Mismo patrón que Súper: rutas en `routes/api.php` (dentro del grupo con `ApiToken` y nombre `api.`), controladores en `App\Http\Controllers\Api`, forma de los datos en `App\Api\Present`, tests en `ApiTest`. **No repetir reglas ni cálculos**: usar los modelos, servicios y clases `*Data` que ya usan la web y el MCP (las herramientas de `app/Mcp/Tools` son buena guía de qué devolver).

| Fase | Recursos | Reutilizar |
|---|---|---|
| Cocina | Recetas (lista con `pantry=ready\|almost`, ficha con costo, macros, ingredientes por sección y pasos por etapa a factor 1; alta, cambio, baja, calificación, fotos), revisión y envío a la lista (receta y semana), menú semanal (semana, agregar, quitar, nota del día) | `RecipeData`, `RecipeView::data()`, `Recipe::pantryChecks()`, `MenuShopper`, `MealPlanEntry`, `MealDayNote::put()`, MCP `GetRecipe`, `ListRecipes`, `GetMenu`, `PlanMeal` |
| Hogar | Tareas (lista por grupos y calendario del mes, alta, cambio, baja, completar/reabrir, mover de fecha, listas de tareas), bienes (lista, ficha con valor, lecturas, servicios y planes; altas y bajas de cada cosa; fotos), reporte de bienes | `Task::complete()/reopen()`, `TaskCalendar::month()`, `ValidatesTask`, `AssetData`, `MaintenancePlanData`, `AssetReport`, MCP `ListTasks`, `GetAsset`, `RecordAsset` |
| Finanzas | Inversiones (cuentas, movimientos, valores), patrimonio (resumen y serie), deudas (saldos, abonos), pagos recurrentes (pagar con monto y lectura, deshacer, historial), ingresos (recurrentes, recibidos, balance del mes), reportes del súper (gasto, inflación, cambios de precio) | `InvestmentAccountData`, `DebtData`, `FinanceReport`, `IncomeReport`, `RecurringPayment::markPaid()/undo()`, `IncomeSource::markReceived()`, `ReportService`, MCP `GetFinances`, `GetIncomes`, `GetFinanceRecords` |

Para la copia sin conexión, cada lista nueva debe poder pedirse completa (paginada hasta 200 o con un parámetro `detail=1` como `products`).
