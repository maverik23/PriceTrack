# API de PriceTrack para la app (`/api/v1`)

Vive en el servidor (`maverik23/PriceTrack`): `routes/api.php`, `App\Http\Controllers\Api\*`, `App\Api\Present`, `App\Api\ListSheet`; tests en `tests/Feature/ApiTest.php` (Inicio y Súper), `ApiKitchenTest`, `ApiHomeTest` y `ApiFinanceTest`. Lo que la web ya contestaba en JSON (hoja de la lista, despensa, precios en línea, calificar una receta, nota del día, mover una tarea) usa los mismos controladores de la web.

> **Estado:** Inicio, Súper, Cocina, Hogar y Finanzas **existen y están probados** en `main` del servidor (confirma que esté desplegado antes de probar contra un servidor real).

## Generalidades

- Base: `{servidor}/api/v1/`. Siempre responde JSON (aunque no se mande `Accept`).
- **Token**: si el servidor define `API_TOKEN`, cada petición lleva `Authorization: Bearer <token>`; si no, responde `401 {"message": …}`. `API_ENABLED=false` quita la API (todo da 404).
- Probar conexión: `GET ping` → `{"app": "PriceTrack", "api": 1, "time": "…"}`. La app debe comprobar `app == "PriceTrack"`.
- **Errores**: `422 {"message", "errors": {"campo": ["texto en español"]}}`; `404 {"message"}`; `409 {"message", "needs_branch": {…}}` al consultar precios en línea de una tienda que pide sucursal (Chedraui) sin haberla elegido.
- **Paginación** (listas): `?page=` y `?per_page=` (1 a 200, 50 por defecto) → `{"data": […], "meta": {"page", "per_page", "last_page", "total"}}` más campos propios de cada lista.
- Fechas `AAAA-MM-DD`. Dinero y cantidades como números. Unidades: `ml`, `l`, `g`, `kg`, `item` (se muestran ml, L, g, kg, pza).
- Las direcciones de fotos son **relativas a la API** (`attachments/12?thumb=1`) y se piden con el mismo token.
- Al cambiar algo «solo cambia lo que se manda» (`PUT` parcial) en todo: artículos, tiendas, precios, listas, recetas, tareas, bienes, planes, cuentas, deudas, pagos e ingresos. Las fichas traen `input` con el registro tal como lo acepta su `PUT` (para llenar el formulario de edición).
- Las altas, cambios y bajas de un registro dentro de una ficha (valor de un bien, movimiento de una cuenta, saldo de una deuda…) devuelven **la ficha completa ya recalculada** en `data`, con `message`.
- Unidades de repetición (tareas, planes, pagos, ingresos): `day`, `week`, `fortnight` (quincena: el 15 y el último día del mes; solo pagos e ingresos), `month`, `year`; las pantallas de opciones las dan como `{clave: {one: "mes", many: "meses"}}`.

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

## Cocina: recetas

| Método | Ruta | Notas |
|---|---|---|
| GET | `recipes?q=&category=&tag=&rating=&pantry=&detail=` | Paginado (todas, filtradas en el servidor). `pantry=ready` (se pueden hacer con la despensa) o `almost` (les faltan 1 o 2 cosas). Cada una: `id, name, category, servings, yield` ("1.2 kg"), `yield_qty, yield_unit, minutes, rating, tags[], cost` (para sus porciones, con el precio más barato de cada ingrediente), `cost_per_serving, photo, pantry_check {ready, missing[], unchecked}` (null sin ingredientes). Además `pantry`, `counts {all, ready, almost}`, `categories`, `tags`. Con `detail=1` cada una trae la ficha completa. Ejemplo: `recipes_index.json` |
| GET | `recipes/options?recipe_id=` | Para el formulario: `products[]` (`id, name, unit, dimension, piece, cup`: solo se ofrecen las unidades que se pueden convertir), `recipes[]` (las que pueden ser sub-receta de `recipe_id`: `id, name, servings, yield` = dimensión, `yieldLabel`), `units` (`IngredientUnit`: `label, plural, dimension, base`), `yield_units`, `categories`, `tags`, `meals`. Ejemplo: `recipes_options.json` |
| GET | `recipes/{id}` | **Ficha a factor 1** (tal como está escrita, para `servings` porciones). Para otras porciones la app multiplica `quantity`, `cost` y `nutrition.total` de cada cosa por *porciones ÷ servings* (único cálculo de la app). Lo del resumen más: `notes, source_url, cost_exact` (sin redondear), `uncosted` (ingredientes sin precio), `nutrition {total, missing}`, `nutrition_per_serving, grams_per_serving, units` (etiqueta singular/plural de cada unidad), `sections[] {name, items[]}` (cada ingrediente: `id, name, kind` product/recipe/text, `product_id, subrecipe_id, quantity, unit, note, cost, source` "Walmart · $30.00/kg", `status` ok/noprice/text, `pantry` "en casa"/"quedan 300 g"), `steps[] {stage, body, minutes}` (en orden: modo cocinar), `stages[] {name, steps[] {position, body, minutes}}`, `used_in[] {id, name}`, `attachments[]`, `input`. Ejemplo: `recipes_show.json` |
| POST | `recipes` | `name, servings` obligatorios; `category, yield_qty + yield_unit (g, kg, ml, l), minutes, rating, source_url, notes, tags[]`, `ingredients[] {product_id \| subrecipe_id \| name, quantity, unit, note, section}` (con artículo: cantidad y unidad obligatorias y convertibles; con sub-receta: `serving` o la dimensión de lo que rinde), `steps[] {stage, body, minutes}` (o `instructions` en texto, un paso por línea) → `201 {data: ficha, message}` |
| PUT | `recipes/{id}` | Parcial. `ingredients`, `steps` y `tags`, si se mandan, **reemplazan la lista completa** (parte de `input`) |
| DELETE | `recipes/{id}` | Donde era sub-receta queda como texto libre (lo dice `message`) |
| POST | `recipes/{id}/rating` | `rating` 1–5 (0 la quita) → `{rating}` |
| POST | `recipes/{id}/attachments` | **multipart**, igual que en artículos → la ficha |
| GET | `recipes/{id}/shop?servings=` | Revisión antes de mandar a una lista: `servings, new_list` (nombre de la lista nueva), `rows[]` (por artículo: `id` del artículo, `name, category, need` "500 g", `have` ("suficiente", "300 g" o null), `buy, checked` (viene marcado si falta), `inPantry, estimate` (en la tienda más barata para esa cantidad, paquetes completos), `buyLabel`), `estimated_total` (de lo marcado), `unlinked[]` (ingredientes sin artículo: no se mandan), `lists[] {id, name, shopped_on}` (pendientes). Ejemplo: `recipes_shop.json` |
| POST | `recipes/{id}/shop` | `servings?`, `list_id?` (sin ella, lista nueva para hoy), `reviewed=1` + `buy[]` (ids de artículo marcados; desmarcar uno que faltaba lo pone «en casa», marcar uno que cubría la despensa lo pasa a «se acabó»). Sin `reviewed`: lo que falta. **Suma** a lo que ya tenga la lista → `{message, added, skipped[], unlinked[], list}` (`list` = resumen como en `lists`, null si no se agregó nada). 422 si la receta no tiene ingredientes ligados a artículos |
| POST | `products/{id}/subrecipe` | `recipe_id`: cambia este artículo casero por su receta en todas las recetas → `{converted[], skipped[], message}` |

## Cocina: menú semanal

| Método | Ruta | Notas |
|---|---|---|
| GET | `menu?week=` | La semana (lunes a domingo; `week` = cualquier día de ella, por defecto esta): `week_start, week_end, prev, next, total` (costo de lo planeado), `avg_kcal` (por persona en los días con algo), `meals` (claves y nombres), `days[]` (7): `date, day, note, count, cost, macros, partial` (`true` = falta algún dato: el total es aproximado, se muestra con `~`), `meals[]` (las 5 comidas, aunque estén vacías): `meal, label, cost, macros, partial, items[]`; cada platillo: `id, date, meal, meal_label, label, recipe_id, title` (texto libre), `servings, cost` (de las porciones planeadas), `macros` (de **una** porción, con `missing`), `photo`. Ejemplo: `menu.json` |
| POST | `menu` | `planned_on, meal` (breakfast, lunch, snack, dinner, other) y `recipe_id` (+ `servings`, por defecto las de la receta) **o** `title` → `201 {message, id, data: la semana}` |
| DELETE | `menu/{entry}` | `{message, data: la semana}` |
| PUT | `menu/notes/{AAAA-MM-DD}` | `body` (vacío la borra) → `{body}` |
| GET | `menu/shop?week=` | Revisión de todas las recetas de la semana (como la de una receta, con `week`). Ejemplo: `menu_shop.json` |
| POST | `menu/shop` | `week` + `list_id?`, `reviewed`, `buy[]` → como el de una receta |

## Hogar: tareas

| Método | Ruta | Notas |
|---|---|---|
| GET | `tasks?list=&q=` | Pendientes, paginadas en el orden de los grupos (vencidas, hoy, próximas, sin fecha). `list` = id de una lista o `none` (General). Cada una: `id, title, notes, list_id, list, due_on, status` (overdue, today, upcoming, undated, done), `priority` ("Alta"), `priority_value` (3 alta, 2 normal, 1 baja), `repeats` ("Cada mes"), `repeat_every, repeat_unit, completed_at, repeated_from_id, taskable` (`{type: "asset", id, name}` si es el recordatorio de mantenimiento de un bien). Además `today`, `groups[] {key, title, total}` (con todo, no solo la página), `done[]` (hechas en los últimos 7 días), `lists[] {id, name, pending}`, `general_count, all_count`. Ejemplo: `tasks_index.json` |
| GET | `tasks/calendar?month=AAAA-MM&list=&q=` | El mes en semanas de lunes a domingo: `month, label, prev, next, counts {total, done, pending, overdue}`, `completion` (hechas ÷ las que ya tocaban, null sin datos), `projected_count, undated, overdue_before`, `days[]` (múltiplo de 7): `date, in_month, is_today, is_past, counts, tasks[]` (cada tarea en su fecha; una hecha sin fecha, el día en que se hizo), `projected[] {task_id, title, date}` (siguientes fechas de las repetitivas: no son tareas todavía). Además `lists`. Ejemplo: `tasks_calendar.json` |
| GET | `tasks/{id}` | La tarea |
| POST | `tasks` | `title` obligatorio; `notes, task_list_id, due_on, priority, repeat_every + repeat_unit` (ambos o ninguno) → `201 {data, message}` |
| PUT | `tasks/{id}` | Parcial |
| DELETE | `tasks/{id}` | |
| POST | `tasks/{id}/toggle` | La completa o la reabre → `{data, next, message}`. Una repetitiva crea la siguiente (`next`, contada desde hoy); reabrirla la borra. Completar un recordatorio de mantenimiento registra el servicio en el bien |
| PATCH | `tasks/{id}/move` | `due_on` → `{ok, id, due_on, from, label}` (`from` = la fecha anterior, para «Deshacer»). Una hecha da 422. Una de mantenimiento vuelve a la fecha del plan cuando cambia su bien |
| GET | `task-lists` | `data[] {id, name, pending}`. Ejemplo: `task_lists.json` |
| POST · PUT · DELETE | `task-lists` · `task-lists/{id}` | `name` (único). Al borrar una lista sus tareas pasan a General |

## Hogar: bienes

| Método | Ruta | Notas |
|---|---|---|
| GET | `assets?status=&category=&q=&detail=` | Paginado. `status` = `active` (por defecto), `sold`, `retired` o `all`. Cada uno: `id, name, category, brand, model, serial_number, location, purchase_price, purchased_on, status, status_label, disposed_on, disposal_amount, valuation_method, useful_life_years, salvage_value, annual_rate, meter_unit, notes, warranty_until, warranty_active, current_value` (null si ya no está en uso), `gain` (contra la compra), `current_meter, maintenance_cost_year, maintenance_cost_total, ownership_cost, photo, plans[]`. Además `summary {value, maintenance_year, overdue[], soon[]` (planes), `warranties[] {id, name, warranty_until}}`. Con `detail=1`, fichas. Ejemplo: `assets_index.json` |
| GET | `assets/options` | `statuses, methods, units, categories, locations`. Ejemplo: `assets_options.json` |
| GET | `assets/{id}` | Lo de la lista más `valuations[] {id, valued_on, value, notes}`, `readings[] {id, read_on, reading}`, `services[] {id, done_on, plan_id, plan, meter_reading, cost, provider, notes, task_id}` (del más nuevo al más viejo), `series[] {x, y}` (valor en el tiempo, ≤ ~120 puntos), `tasks[]` (pendientes ligadas), `attachments[]`, `input`. Ejemplo: `assets_show.json` |
| POST | `assets` | `name` obligatorio; `status` (active por defecto; sold/retired piden `disposed_on`), `valuation_method` (none por defecto; `straight_line` pide `useful_life_years` y `salvage_value?`; `annual_rate` pide `annual_rate`, − se deprecia), y los demás campos → `201` con la ficha |
| PUT | `assets/{id}` | Parcial. Venderlo o darlo de baja quita sus recordatorios pendientes |
| DELETE | `assets/{id}` | Con todo su historial, fotos y recordatorios |
| POST | `assets/{id}/attachments` | **multipart** (fotos y documentos: pdf, Word, Excel…), igual que en artículos → la ficha |
| POST | `assets/{id}/valuations` | `valued_on, value, notes?` (uno por día: corrige el de ese día) → la ficha |
| POST | `assets/{id}/readings` | `read_on, reading` (en `meter_unit`: km, horas) → la ficha |
| POST | `assets/{id}/logs` | Servicio hecho: `done_on, maintenance_plan_id?, meter_reading?, cost?, provider?, notes?`. Uno de un plan mueve su siguiente fecha y su tarea → la ficha |
| DELETE | `assets/{id}/valuations/{v}` · `…/readings/{r}` · `…/logs/{l}` | → la ficha (404 si el registro es de otro bien) |
| POST | `assets/{id}/plans` | `name`; `every + every_unit` y/o `every_meter`, `estimated_cost?, notes?`. Crea su recordatorio en Tareas (lista «Mantenimiento») → `201` la ficha. Cada plan: `id, name, every, every_unit, every_meter, interval` ("Cada 6 meses o 10,000 km"), `estimated_cost, notes, status` (overdue, soon, ok, none), `next_due_on, next_meter, due_label, last_done_on, task_id` |
| PUT · DELETE | `assets/{id}/plans/{plan}` | Parcial · borra el plan y su recordatorio (los servicios se conservan) |
| GET | `reports/assets` | `years[]`, `rows[] {asset_id, name, status, by_year {año: costo}, total, value, gain, ownership}` (costo de tenerlo: compra + mantenimiento − lo que vale hoy o lo que se recibió), `totals {año: costo}`, `summary` (como en `assets`), `plans[]` (con intervalo, del más urgente). Ejemplo: `reports_assets.json` |

## Finanzas: inversiones y patrimonio

Todo en pesos salvo `last_value` / `value` de una captura, que van en la moneda de la cuenta.

| Método | Ruta | Notas |
|---|---|---|
| GET | `investments?detail=` | Todas (pocas: sin paginar; abiertas primero, de mayor a menor valor). Cada una: `id, name, institution, type, type_label, currency, notes, closed_on, closed, value` (hoy, en pesos), `last_value, last_exchange_rate, contributed` (aportado neto), `gain, simple_return, annualized_return` (TIR; proporción: 0.1 = 10 %), `as_of` (fecha de la última captura: a ella se mide), `stale` (sin valor en 45 días). Además `summary {value, contributed, gain, annualized_return, stale[] {id, name}}`. Ejemplo: `investments_index.json` |
| GET | `investments/options` | `types, institutions, kinds` (deposit, withdrawal, income, fee) |
| GET | `investments/{id}` | Más `totals {deposit, withdrawal, income, fee}`, `transactions[] {id, occurred_on, kind, kind_label, amount, exchange_rate, amount_mxn, notes}`, `valuations[] {id, valued_on, value, exchange_rate, value_mxn}`, `series[]` (dos series para la gráfica: «Valor» y «Aportado neto», `data[] {x, y}`), `input`. Ejemplo: `investments_show.json` |
| POST | `investments` | `name, type`; `institution, currency` (MXN por defecto), `notes, closed_on` → `201` la ficha |
| PUT · DELETE | `investments/{id}` | Parcial (la moneda no cambia si ya hay registros: 422) · borra con su historial |
| POST | `investments/{id}/transactions` | `occurred_on, kind, amount` (positivo, en la moneda de la cuenta), `exchange_rate` (obligatorio si no es MXN), `notes?` → `201` la ficha. Solo aportaciones y retiros son flujos; dividendos y comisiones ya están en el valor |
| POST | `investments/{id}/valuations` | `valued_on, value, exchange_rate` (si no es MXN): uno por día → la ficha |
| DELETE | `investments/{id}/transactions/{t}` · `…/valuations/{v}` | → la ficha |
| GET | `net-worth?months=` | Patrimonio = inversiones + bienes − deudas: `now, month_ago, year_ago` (`{date, investments, assets, debts, total}`), `series[]` (al cierre de cada mes, `months` 24 por defecto hasta 120, y hoy), `accounts[] {id, name, value}`, `assets[] {id, name, value}`, `debts[] {id, name, balance}`. Ejemplo: `net_worth.json` |

## Finanzas: deudas

| Método | Ruta | Notas |
|---|---|---|
| GET | `debts?detail=` | Todas (sin paginar; vigentes primero, de mayor a menor saldo). Cada una: `id, name, lender, type, type_label, original_amount, annual_rate` (%), `notes, started_on, paid_off_on, paid_off, balance` (hoy), `total_paid, monthly_payment` (suma de sus pagos recurrentes activos), `months_left`. Además `total, monthly, types`. Ejemplo: `debts_index.json` |
| GET | `debts/{id}` | Más `balances[] {id, balance_on, balance}`, `payments[]` (como en pagos), `recurring_payments[] {id, name, amount, interval, active, next_due_on}`, `series[] {x, y}` (saldo en el tiempo), `input`. Ejemplo: `debts_show.json` |
| POST | `debts` | `name, type` (mortgage, car, card, personal, other), `original_amount`; `lender, annual_rate, started_on, paid_off_on, notes` → `201` la ficha |
| PUT · DELETE | `debts/{id}` | Parcial · |
| POST | `debts/{id}/balances` | `balance_on, balance` (del estado de cuenta; uno por día) → la ficha |
| POST | `debts/{id}/payments` | Abono o pago suelto: `paid_on, amount, notes?` → `201` la ficha. Las mensualidades se pagan desde su pago recurrente |
| DELETE | `debts/{id}/balances/{b}` · `…/payments/{p}` | → la ficha. Un pago de un pago recurrente se deshace (su fecha regresa) |

## Finanzas: pagos (recurrentes y únicos)

En la web viven con los ingresos, en la pantalla «Ingresos y pagos» (pestaña Pagos). Cada pago registrado trae `kind`: `recurring` (de un pago recurrente), `one_time` (único: gasolina, una comida fuera) o `debt` (abono suelto a una deuda), con `name` (el concepto, el del recurrente o "Abono a …") y `category`.

| Método | Ruta | Notas |
|---|---|---|
| GET | `recurring?q=&detail=` | Los activos, paginados por próxima fecha. Cada uno: `id, name, category, amount` (de referencia: el último pagado), `meter_unit, every, every_unit, interval` ("Cada 2 meses"), `next_due_on, status` (overdue, soon = en 7 días, ok, inactive), `paid_up, debt_id, debt, active, notes, monthly` (normalizado al mes), `asks_reading` (pedir la lectura al pagar: tiene unidad o por el nombre es luz, agua o gas), `suggested_meter_unit` (kWh, m³), `last_payment` (como en pagos, con `consumption` y `unit_price`). Además `inactive[]` (pausados, completos), `monthly, by_category {categoría: al mes}`, `recent[]` (últimos 15 pagos de todo). Con `detail=1`, fichas. Ejemplo: `recurring_index.json` |
| GET | `recurring/options` | `categories` (las mismas de los pagos únicos; sugeridas la primera vez), `units, debts[] {id, name}` |
| GET | `recurring/{id}` | Más `history[]` (todos sus pagos, del más reciente: `id, recurring_payment_id, debt_id, paid_on, due_on` (el recibo que pagó), `amount, reading, consumption` (lectura − la anterior; null si el medidor se reinició), `unit_price` (monto ÷ consumo), `notes`) e `input`. Ejemplo: `recurring_show.json` |
| POST | `recurring` | `name, amount, every, every_unit, next_due_on`; `category, meter_unit, debt_id` (mensualidad de una deuda), `active` (true por defecto), `notes` → `201` la ficha |
| PUT · DELETE | `recurring/{id}` | Parcial (`active: false` lo pausa) · su historial se conserva |
| POST | `recurring/{id}/pay` | Paga el recibo que toca: `amount?` (vacío = el de referencia; el pagado queda de referencia), `paid_on?` (hoy), `reading?` (lectura final del periodo; si el pago aún no tiene unidad pide `meter_unit`, que se guarda), `notes?` → `201 {data: ficha, payment_id, message}` ("… 210 kWh a $2.14/kWh. El siguiente recibo: …"). Avanza `next_due_on` un periodo desde la fecha que tocaba |
| POST | `paid` | Pago único: `name, amount, paid_on` (hasta hoy), `category?, notes?` → `201 {data: pago, message}`. Cuenta en `incomes` → `paid` y en el balance |
| PUT | `paid/{payment}` | Corrige un pago (parcial: `paid_on, amount, reading, notes`; `name, category` también, y en uno único el concepto no puede quedar vacío) → `{data: pago, message}` |
| DELETE | `paid/{payment}` | Lo deshace: si era el último de su pago recurrente, la fecha regresa (uno único solo se borra) |

## Finanzas: ingresos

| Método | Ruta | Notas |
|---|---|---|
| GET | `incomes?month=AAAA-MM` | El mes (el actual por defecto): `month, label, prev, next, is_current_month, total, money, in_kind` (vales, regalos: lo que valen), `by_category`, `spent {super, payments, total}` (súper de todas las tiendas + pagos registrados), `balance`, `incomes[] {id, income_source_id, name, category, kind, kind_label, amount, notes, received_on, due_on}`, `series[]` (12 meses: `month, label, money, in_kind, total`), `average` (desde el primer mes con ingresos), `expected {money, in_kind, total}` (al mes, de las recurrentes activas), `sources[]` y `inactive[]` (recurrentes: `id, name, category, kind, kind_label, amount, every, every_unit, interval, next_on, status, monthly, active, notes, input`), `categories, kinds, units` y `paid` (lo pagado en el mes: `total, recurring, one_time, debt` (abonos), `by_category`, `payments[]` (como en pagos), `series[]` (12 meses: `month, label, recurring, one_time, total`), `expected_monthly` (pagos recurrentes activos al mes), `categories`). Ejemplo: `incomes.json` |
| POST | `incomes` | Eventual: `name, received_on, amount, kind` (money, in_kind), `category?, notes?` → `201 {data, message}` |
| DELETE | `incomes/{id}` | Si vino de una recurrente, su fecha regresa |
| POST | `income-sources` | `name, kind, amount, every, every_unit, next_on`; `category, active, notes` → `201 {data, message}` |
| PUT · DELETE | `income-sources/{id}` | Parcial · lo recibido se conserva |
| POST | `income-sources/{id}/receive` | Marca recibido el periodo que toca: `received_on?, amount?` (vacío = el de siempre) → `201 {data: la recurrente, income, message}` |

## Reportes del súper

Rango `from` / `to` (`AAAA-MM-DD`; por defecto los últimos 12 meses, 3 en cambios). Las variaciones van como proporción (0.05 = +5 %).

| Método | Ruta | Notas |
|---|---|---|
| GET | `reports/spending?from=&to=&stores=` | Gasto (solo lo marcado como comprado, en la fecha de su lista; el ticket de cada tienda manda). `stores` = `super` (por defecto), `other` (Home Depot…) o `all`; `excluded {amount, stores[]}` = lo que quedó fuera. `total, start, end, span` (días con datos), `days, daily, weekly, monthly, annual, lists, per_list`, `short_monthly` / `short_annual` (con menos de 28 / 90 días el mensual / anual es **proyección**: avisarlo), `by_week[], by_month[], by_year[]`, `by_provider[]` y `by_category[]` (`name, amount, share`), `gaps {unmarked, future}` (artículos sin marcar en listas pasadas y compras en listas con fecha futura). Ejemplo: `reports_spending.json` |
| GET | `reports/inflation?from=&to=` | `overall, annualized, up, down, flat, observations`, `monthly[] {month, label, index (base 100), change}`, `products[]` (del que más subió al que más bajó: `product_id, product, change, first_unit_price, last_unit_price, per, from, to, providers[] {provider_id, provider, change, first_unit_price, last_unit_price}`). Ejemplo: `reports_inflation.json` |
| GET | `reports/changes?from=&to=` | Paginado, del más nuevo: cada precio contra el anterior del mismo artículo en la misma tienda (por precio unitario): `product_id, product, provider_id, provider, date, previous_date, days, from_price, to_price, from_unit_price, to_unit_price, per, change`. Además `from, to, up, down, avg_change`. Ejemplo: `reports_changes.json` |

## Copia sin conexión: qué pedir

Además de lo de Súper (`docs/OFFLINE.md` §2): `recipes?detail=1&per_page=200` (cada una se guarda también como `recipes/{id}`), `recipes/options`, `menu` (esta semana y la siguiente: `menu?week=`), `tasks?per_page=200`, `tasks/calendar` (este mes), `task-lists`, `assets?status=all&detail=1&per_page=200` (y `assets/{id}`), `assets/options`, `reports/assets`, `investments?detail=1`, `debts?detail=1`, `recurring?detail=1&per_page=200` (y `recurring/{id}`), `incomes`, `net-worth` y la primera página de cada reporte del súper.
