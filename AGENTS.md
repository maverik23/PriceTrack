# PriceTrack: notas para agentes

App del hogar: comparador de precios del súper (el origen), y además cocina (recetas y menú), hogar (tareas, bienes y mantenimiento) y finanzas (inversiones y patrimonio). El inicio resume las cuatro secciones (`DashboardController`). Laravel 13 + Sail (PHP 8.5, MySQL 8.4), Blade + Tailwind v4 + Chart.js. Sin login. La interfaz está en español (México).

## Entorno

- **Todo corre con Sail.** No uses `php`, `composer` ni `npm` del host (no hace falta tenerlos instalados). No instales PHP ni Laravel Boost.
- La app se abre en http://localhost:8080 (`APP_PORT=8080`) y la zona horaria es `America/Mexico_City`.
- Producción: `Dockerfile` (FrankenPHP, PHP 8.5) + `docker-compose.prod.yml` (app + MySQL 8.4, puerto 8090). Al arrancar, `docker/entrypoint.sh` genera y guarda `APP_KEY` si no viene, migra, corre `pricetrack:install` (crea las tiendas solo si no hay ninguna) y cachea config/rutas/vistas.

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate --seed            # base limpia: solo proveedores
./vendor/bin/sail artisan db:seed --class=DemoDataSeeder   # un año de datos demo
./vendor/bin/sail npm run build                     # recompilar después de cambiar vistas o JS
./vendor/bin/sail artisan test                      # usa la BD MySQL "testing"
./vendor/bin/sail pint                              # formato
```

## Dominio

- `providers` → `products` (presentación habitual: `size` + `unit`) → `prices` (precio, `pack_qty`, `size`, `unit`, `observed_on`, `shopping_list_id` opcional).
- `shopping_lists` (`shopped_on`) → `shopping_list_items` (`quantity`, `price_id` = dónde se compró; `null` = pendiente).
- **Unidades** (`app/Enums/Unit.php`): ml, l, g, kg, item. Se normaliza a ml / g / pza y se muestra en $/L, $/kg y $/pza. Un precio debe tener la misma dimensión que su artículo (volumen, peso o piezas).
- `Price` calcula `base_qty` y `unit_price` en el evento `saving`. No los asignes a mano.
- **El más barato** se decide por el menor `unit_price` entre el **último** precio de cada proveedor (`Product::latestPrices()`).
- **Cantidad** de un artículo en una lista = lo que se necesita, en presentaciones habituales (`ShoppingListItem::needBaseQty()`). En la hoja se puede escribir con unidad (`610 g` → 0.61 de una presentación de 1 kg); se guarda con 4 decimales.
- **Lo pagado** (`ShoppingListItem::subtotal()`, gasto, ticket del pie) = `Price::costFor(necesidad)`: si el precio es **a granel** (`prices.bulk`: el precio es por el contenido, "por kg", "por 100 g") se cobra lo justo; si no, los **paquetes completos** que cubren la necesidad (`Price::packsFor()`, con `PACK_TOLERANCE` de 15 % de un paquete: 946 ml ≈ 1 L, 907 g ≈ 1 kg; 7 latas siguen siendo 7). Necesitas 1 kg y Sam's vende 2 kg: pagas los 2 kg.
- **Total de cada tarjeta, estimados y "Total por proveedor"** (pie de la hoja; la otra fila del pie es el ticket de lo comprado en cada tienda): lo que costaría la necesidad en esa tienda con `costFor()` (paquetes completos o a granel). El estimado de un pendiente es el de la tienda más barata para esa necesidad (`ShoppingListItem::estimatedSubtotal()`, `bestBuy()` en el JS). El ★ y el verde de la celda siguen marcando el más barato **por unidad**; el total en verde marca dónde sale más barato llevarte lo que necesitas.
- **Tarjetas de la hoja**: *Ahorro vs. el más caro* = lo que costaba lo comprado en la tienda más cara, con sus paquetes completos, − lo pagado. *Total más barato* = lo comprado, cada artículo en la tienda más barata para su cantidad (`bestBuy()`); *posible ahorro* = total comprado − total más barato.
- **Gasto**: solo los items con `price_id`, en la fecha de la lista. Los promedios salen del gasto por día del periodo real (mínimo una semana). El reporte avisa de artículos sin marcar y de compras en listas con fecha futura (`ReportService::spendingGaps()`).
- **Inflación**: índice mensual encadenado (media geométrica) por par artículo + proveedor, arrastrando el último precio conocido.
- Los reportes viven en `app/Services/ReportService.php`.
- **Código de barras** (`products.barcode`, opcional y único): se guarda normalizado con `App\Support\Barcode::normalize()` (solo dígitos; UPC-A de 12 → EAN-13 con 0 inicial; un código mexicano `750…` de 12 dígitos, o con ceros delante como los de Walmart `00750102051534`, es un EAN-13 sin dígito verificador y se completa). `normalizeBarcode()` de `list-grid.js` lo replica: si cambias uno, cambia el otro. `Product::findByBarcode()` busca también en los códigos de cada tienda.
  - `GET products/lookup?barcode=` (JSON) busca primero en los artículos guardados y si no, en internet con `App\Services\BarcodeLookup` (Open Food Facts y sus bases hermanas en paralelo, luego UPCitemdb; caché 30 días / 1 día si no se encontró). Devuelve `product` (local), `suggestion` (nombre con presentación, marca, categoría, size/unit totales) y `error`. Se apaga con `BARCODE_LOOKUP=false`. En tests usa `Http::fake()`.
  - Escáner: `<x-scan-button for="id-del-input" />` abre la ventana global `<x-barcode-scanner />` (layout, `resources/js/barcode-scanner.js`). Al leer escribe el código en ese input y dispara `input` + `barcode-scanned`. Usa el `BarcodeDetector` nativo o zxing en WASM (`barcode-detector`, servido desde `public/build`, sin CDN). La cámara en vivo exige HTTPS o localhost; si no, se toma una foto.
  - Formulario de artículo: Alpine `productForm` (`resources/js/product-form.js`) llena solo los campos vacíos; marca y categoría se cambian con `Alpine.$data(el).value`.
- **El artículo en cada tienda** (`product_links`, sección "En cada tienda" de la ficha): cada tienda vende su versión ("Agua": Bonafont en una, Kirkland en otra), con su `brand`, `barcode`, `sku` (código de la tienda), `store_name` y `url` opcional. Al obtener precios se llenan solos; la marca pasa por `Product::canonicalValue()` (que también mira estas marcas). En la hoja, la fila trae `links` (solo direcciones) y `stores` (marca, nombre, código) y la marca se ve en la tarjeta de cada tienda.
- **Precios en línea**: dirección del artículo en cada tienda (`product_links.url`) + código postal global en `settings` (`Setting::POSTAL_CODE`, se edita en Proveedores). `App\Services\PriceFetcher` lee el precio (y nombre, presentación, marca, EAN y sku):
  - Chedraui (VTEX): `/api/catalog_system/pub/products/search/{slug}/p`. Ese precio es general y **no es el de ninguna sucursal** (ejote: $29.50 en catálogo, $44.90 o $47.50 según la tienda), así que con código postal se usa el de **tu tienda**: la elegida en Proveedores (`Setting::CHEDRAUI_STORE`, un vendedor `chedrauimx0112`) o, en automático, la más cercana con existencia, de las tiendas de `/api/checkout/pub/pickup-points?postalCode=` (ordenadas por distancia; `PriceFetcher::chedrauiStores()`, caché 1 día; si no hay, `regions`). Una sola `orderForms/simulation` con un renglón por tienda; "cannotBeDelivered" solo dice que no envía a ese CP (el precio vale), "withoutStock" va al final; si ninguna lo tiene se informa, nunca el precio general. `sellingPrice` viene en centavos y por `unitMultiplier` (0.1 kg de ejote → se divide). El resultado dice de qué tienda salió. Productos con `measurementUnit` kg se guardan a granel por kg; los demás traen la especificación "Contenido del empaque" ("6 piezas de 355ml").
  - Costco (SAP Spartacus): OCC `/rest/v2/mexico/products/{código}?fields=code,name,price(value),classifications(…)`; el precio ya trae el cupón y la característica "Contenido Neto" da la presentación ("8 pzas de 387 g").
  - Cualquier otra: JSON-LD `Product → offers.price`, metaetiquetas de precio o el `corePrice` de Amazon. Home Depot da el precio de su tienda por defecto.
  - Walmart y Sam's (PerimeterX), Soriana (Cloudflare) y Amazon bloquean a los programas. Esas tiendas (`services.price_fetch.browser_hosts`), y cualquiera que responda con bloqueo, se abren con el **navegador de PriceTrack** (`docker/browser`, servicio `browser` en los compose; `PRICE_FETCH_BROWSER_URL`): Chromium real **con ventana** en una pantalla virtual (xvfb) que devuelve el HTML ya cargado (`GET /html?url=`). Sin ventana (headless) lo bloquean, y un perfil guardado termina marcado: cada consulta usa un contexto limpio. Probado: Walmart, Sam's y Amazon pasan; Soriana bloquea por IP de nube. Sin el servicio se informa "Bloqueado por la tienda". Solo se consultan páginas https de dominios públicos.
  - `PriceUpdater` registra con `Price::record()` (fecha de la lista o de hoy, igual que capturar en la hoja) y toma la presentación (piezas, contenido, unidad) de los datos de la tienda, si no del nombre en la tienda ("… 8 pzas de 375 g", "… 1.8L"; `BarcodeLookup::parseQuantity()` entiende piezas/latas/botellas "de"/"con"), si no la última usada en esa tienda, si no la habitual; siempre de la misma dimensión que el artículo. En tests: `Http::fake()`, `services.price_fetch.resolve_hosts = false` y `PRICE_FETCH_BROWSER_URL` vacío (phpunit.xml).
  - Hoja: "Obtener precios" consulta artículo por artículo (`POST lists/{list}/items/{item}/prices/fetch`) y pegar una dirección en una celda guarda el enlace y consulta (`POST …/links`). La ficha del artículo tiene los enlaces y su propio botón.
- **Cocina** (`recipes`, `recipe_ingredients`, `meal_plan_entries`):
  - Un ingrediente es un artículo (`product_id`, con cantidad y unidad **de su misma dimensión**: obligatorio) o texto libre (cantidad y unidad opcionales). Guarda el nombre para no perderlo si el artículo se elimina (`nullOnDelete`). `RecipeIngredient::productBaseQty()` devuelve null si la unidad ya no coincide con el artículo (alguien lo cambió de kg a pza): no se cuesta ni se manda a la lista.
  - **Costo** = lo que se usa × `unit_price` de `Product::cheapestPrice()` (no paquetes). `Recipe::cost($porciones)` escala con `factor()`.
  - `App\Services\MenuShopper` suma lo que piden las recetas por artículo y lo **suma** a la lista en presentaciones habituales (no evita duplicar si se manda dos veces).
  - Menú: semana de lunes a domingo (`?week=` acepta cualquier día; si no es fecha, esta semana). Comidas en `MealPlanEntry::MEALS`. Las consultas de la semana usan rango semiabierto (`>= lunes`, `< lunes siguiente`), no `whereBetween`, porque la fecha se guarda con hora.
  - Unidades de ingrediente: `App\Enums\IngredientUnit` (las de `Unit` más `cup` 240 ml, `tbsp` 15 ml, `tsp` 5 ml). **No** se agregan a `Unit`: los precios no se capturan en tazas. `Product::fromIngredient($unidad, $cantidad)` pasa a la unidad base del artículo con `cup_grams` (volumen ↔ peso) y `piece_weight` (piezas ↔ peso); `missingConversion()` dice qué dato falta. `RecipeIngredient::productBaseQty()` usa esa conversión (null si ya no se puede) y de ahí salen costo, lista de compras y macros.
  - Nutrición en `products` (`kcal`, `protein`, `carbs`, `fat`): por 100 g/ml, o **por pieza** si el artículo se mide en piezas. `Recipe::nutrition($porciones)` (total, por porción, cuántos artículos no tienen datos) y `gramsPerServing()` (en crudo). `BarcodeLookup` trae `nutrition` de Open Food Facts (`nutriments`) y `productForm` la llena si el artículo no es por piezas.
  - El formulario de receta es Alpine `recipeForm` (`resources/js/recipe-form.js`): con artículo solo ofrece las unidades que se pueden convertir (`convertible()` replica `Product::fromIngredient()`: si cambias uno, cambia el otro).
- **Tareas** (`task_lists` → `tasks`; sin lista = "General", al borrar una lista sus tareas pasan a General):
  - `priority` 1 baja, 2 normal, 3 alta (`Task::PRIORITIES`). Repetición: `repeat_every` + `repeat_unit` (`day|week|month|year`, ambos o ninguno).
  - **La siguiente fecha se cuenta desde el día en que se completa** (`Task::nextDueOn()`, meses y años sin desbordar: 31 ene + 1 mes = 28 feb). `Task::complete()` crea la siguiente con `repeated_from_id`; `reopen()` la borra si sigue pendiente. `POST tasks/{task}/toggle` hace una u otra.
  - `Task::status()`: overdue / today / upcoming / undated / done. `taskable` (morph opcional) es para ligar una tarea a otra cosa (un bien); si el modelo tiene `taskLink()`, el renglón muestra el enlace.
- **Bienes** (`assets` → `asset_valuations`, `meter_readings`, `maintenance_plans`, `maintenance_logs`):
  - Estado `active|sold|retired` (vendido o de baja: `disposed_on` + `disposal_amount`). Método de valor `none|straight_line|annual_rate` (`Asset::METHODS`); el controlador vacía los campos de los otros métodos.
  - `Asset::valueOn($fecha)`: última captura hasta esa fecha (o la compra) ajustada con el método desde esa fecha; null antes de la compra; 0 desde la baja. `gain()`, `ownershipCost()`, `maintenanceCost($año)`, `currentMeter()` (la lectura más alta de lecturas y servicios), `valueSeries()` (gráfica, ≤ ~120 puntos).
  - Una captura de valor por día (se corrige la de ese día).
  - `MaintenancePlan`: por tiempo (`every` + `every_unit`, mismas unidades que las tareas) y/o por uso (`every_meter`). `nextDueOn()` desde el último servicio (o la compra, o la creación del plan); `nextMeter()` desde la lectura del último servicio (o la primera lectura). `status()`: overdue / soon (30 días o 10 % del intervalo) / ok / none.
  - **Enlace con tareas**: cada plan mantiene una tarea pendiente (`maintenance_plans.task_id`, lista "Mantenimiento", `taskable` = el bien) con `syncTask()`. Guardar o borrar un servicio la mueve (eventos de `MaintenanceLog`). Completar la tarea (`Task::complete()`) registra un servicio con `task_id` y crea la siguiente tarea; reabrirla (`Task::reopen()`) borra ese servicio y la vuelve el recordatorio. Vender, dar de baja o borrar el bien, o borrar el plan, quita las tareas pendientes.
  - Rutas anidadas con `scopeBindings()`: los parámetros se llaman como la relación del bien (`{meterReading}`, `{maintenanceLog}`, `{maintenancePlan}`), si no Laravel no encuentra la relación.
  - Reportes en `App\Services\AssetReport` (`summary()`, `plans()`, `maintenanceByYear()`, `totalValueOn()`); pantalla `reports/assets`.
- **Inversiones** (`investment_accounts` → `investment_transactions`, `investment_valuations`), todo manual:
  - Movimientos `deposit|withdrawal|income|fee` con `amount` positivo en la moneda de la cuenta y `exchange_rate` (pesos por unidad; 1 en MXN, obligatorio si no). Solo aportaciones y retiros son flujos (`InvestmentTransaction::contribution()`); dividendos, intereses y comisiones son informativos (ya están en el valor; si te los pagan fuera, es un retiro).
  - `InvestmentAccount::valueOn($fecha)`: última captura hasta esa fecha × su tipo de cambio; sin capturas, lo aportado; 0 desde `closed_on`. `asOf()` = fecha de la última captura (o el cierre); `gain()` y `annualizedReturn()` se miden a esa fecha. No se puede cambiar la moneda de una cuenta con registros.
  - `App\Support\Xirr::calculate([[fecha, monto], …])`: Newton y, si no converge, bisección; años de 365 días (igual que Excel). Null sin cambio de signo.
  - `App\Services\FinanceReport`: `investmentSummary()`, `debts()`, `debtsOn()`, `netWorthOn()` (inversiones + `AssetReport::totalValueOn()` − deudas), `netWorthSeries()` (cierre de cada mes y hoy). Pantalla `net-worth`.
- **Deudas y pagos recurrentes** (`debts` → `debt_balances`; `recurring_payments`; `payments`):
  - `Debt::balanceOn($fecha)`: última captura hasta esa fecha (o `original_amount` en `started_on`), con interés compuesto de `annual_rate`/12 por días ÷ 30.436875, menos los pagos **después** de la captura (la captura ya incluye los de ese día). 0 antes de `started_on` y desde `paid_off_on`. `monthsLeft()` con la fórmula de amortización y la suma de sus pagos recurrentes activos (`monthlyPayment()`).
  - `RecurringPayment::markPaid()`: crea un `Payment` (con `due_on` = la fecha que tocaba y `debt_id` si es mensualidad) y avanza `next_due_on` **un periodo desde la fecha que tocaba** (día fijo, a diferencia de las tareas). `undo()` borra el pago y, si era el último, regresa la fecha. `monthlyAmount()` normaliza a mes. Los pagos sueltos a una deuda (abonos) son `Payment` sin `recurring_payment_id`.
  - El formulario de edición manda `active=0` en un hidden antes del checkbox; el alta asume activo.
- **Fotos y documentos** (`attachments`, polimórfico con el nombre de la clase, como `taskable`): trait `HasAttachments` (`attachments()`, `coverPhoto()` = la primera foto; borra los archivos al borrar el dueño). Hoy en `Asset` y `Recipe`.
  - Disco `local` (`storage/app/private/attachments/AAAA/MM/uuid.ext`, en el volumen `storage` de producción); se sirven con `GET attachments/{attachment}` (`?thumb=1`, `?download=1`), nunca con enlace público.
  - El servidor de producción **no tiene GD**: `resources/js/attachment-upload.js` (Alpine `attachmentUpload`) reduce las fotos a 2000 px y hace una miniatura de 480 px (`thumbs[i]`) en el navegador antes de enviar; si no puede leer la imagen (HEIC en Chrome) sube el original. Panel reutilizable `attachments/_panel` (`$owner`, `$action`).
  - Límites: `Attachment::MAX_KB` (20 MB por archivo), 10 por subida; `docker/php.ini` (`upload_max_filesize = 20M`, `post_max_size = 64M`). En tests: `Storage::fake('local')`.
- **Hoja de la lista** (`lists/show`): tabla tipo Excel en Alpine (`resources/js/list-grid.js`) alimentada por `App\Support\ListGrid`. Filas = artículos, columnas = proveedores, celdas = último precio. Editar una celda hace `POST lists/{list}/items/{item}/prices`, que registra el precio con `ShoppingList::priceDate()` (la fecha de la lista, nunca futura). Si ese día ya se capturó desde la misma lista, corrige ese registro; si no, crea uno nuevo y conserva el historial. Si la compra estaba en ese proveedor, pasa al precio nuevo. Los cálculos en vivo del JS replican los de los modelos: si cambias uno, cambia el otro. Un precio a granel se escribe en la celda como `54/kg` o con la presentación `por kg` / `por 100 g`.

## Diseño ("papel y jade")

Todo el sistema vive en `resources/css/app.css`; las vistas no llevan clases `dark:`.

- **Colores**: las vistas solo usan cuatro familias de Tailwind, redefinidas en `@theme`: `slate` (papel y tinta, neutros cálidos), `emerald` (jade: marca y "lo bueno/barato"), `red` (chile: alertas, subidas) y `amber` (cempasúchil: avisos). No uses otras familias (`blue-*`, `gray-*`…): no cambian con el tema.
- **Superficies y rellenos**: tarjetas, campos y menús flotantes usan `bg-surface`, nunca `bg-white`. Un relleno sólido con texto encima es `bg-brand text-on-brand` (hover `bg-brand-strong`), nunca `bg-emerald-600 text-white`: en oscuro la escala se invierte y el texto blanco dejaría de leerse.
- **Tema claro/oscuro**: `<html data-theme>` (lo pone un script en el `<head>` antes de pintar; `resources/js/theme.js` y `<x-theme-toggle />` cambian entre automático, claro y oscuro, guardado en `localStorage`). El oscuro redefine las mismas variables, así que una vista que respete lo anterior funciona en los dos sin tocarla. Revisa las pantallas nuevas en ambos.
- **Secciones**: Súper (jade), Cocina (chile), Hogar (azul talavera), Finanzas (ciruela) e Inicio (cempasúchil). `<body data-section>` da el color de la pantalla (lavado de fondo, enlace activo) y `data-nav="cocina"` en cualquier elemento le da las variables `--sec`, `--sec-soft` y `--sec-ink` de esa sección; las usan `.icon-tile`, `.eyebrow`, `.nav-dot` y `.stat-card`.
- **Navegación** (`layouts/app.blade.php`, arreglo `$sections`): barra lateral en escritorio (`lg`), y en móvil barra superior + pestañas abajo; cada pestaña abre una hoja con las pantallas de su sección. Una pantalla nueva se agrega a ese arreglo con su icono.
- **Tipografía**: Figtree (`font-sans`) para texto y Bricolage Grotesque (`font-display`) para `h1`, `.card-title` y `.stat-value`.
- **Formas**: botones y campos redondeados (`.btn` es píldora), tarjetas `rounded-2xl` con `--shadow-card`. Usa las clases de componente antes que utilidades sueltas.
- **Iconos**: `<x-icon name="shopping-cart" class="size-5" />` (trazos de Lucide en línea, en `components/icon.blade.php`; para uno nuevo, copia ahí sus trazos). Logotipo: `<x-logo />` y `public/favicon.svg`.
- **Gráficas**: los colores salen de `palettes` en `resources/js/app.js` según el tema y se redibujan al cambiarlo. No pases colores hexadecimales desde la vista; si una serie necesita un color fijo usa `'color' => 'chile'` (`jade`, `talavera`, `sol`, `chile`, `ciruela`).
- **Paginación**: `resources/views/vendor/pagination/tailwind.blade.php`.

## Convenciones

- Modelos con atributos de Laravel 13 (`#[Fillable([...])]`) y `casts()`.
- Helpers globales en `app/helpers.php`: `money()`, `qty()`, `num()` (valores para inputs) y `pct()`.
- Vistas: `@extends('layouts.app')` + `@section('content')`. Clases de componentes (`.btn`, `.card`, `.input`, `.table`, `.badge-*`) en `resources/css/app.css`.
- Gráficas: `<canvas data-chart="{{ json_encode([...]) }}">`; las inicializa `resources/js/app.js`. **No uses `@json([...])` con arrays de varios elementos**: Blade separa sus argumentos por comas y la vista se rompe.
- Los grids necesitan `grid-cols-1` en la versión móvil; si no, las tablas desbordan la página.
- Los textos de validación están en `lang/es/validation.php`.
- Campos de texto libre con sugerencias (marca, categoría): usa `<x-combobox name="…" label="…" :options="Product::distinctValues('…')" :value="…" noun="… nueva" />` (Alpine `combobox` en `resources/js/combobox.js`). Al guardar, pasa el valor por `Product::canonicalValue()` para reusar la escritura existente ("lacteos" → "Lácteos") y no crear duplicados.
- Los `select` llevan la clase `.input` como los demás campos; el estilo (altura, flecha) sale de `select.input` en `app.css`.
- Los botones dentro de listas de sugerencias llevan `tabindex="-1"`; si no, Tab cae en ellos en lugar de pasar al siguiente campo.
- El parámetro `redirect` de `PriceController` solo acepta rutas internas (`/…`, nunca `//…`).

## Gotchas

- En Blade, un `@endif` pegado a una palabra (`al año@endif`) se compila, pero el **siguiente** `@endif` queda como texto y la vista truena con *unexpected end of file*. Deja un espacio o una etiqueta antes (`</span>@endif`).
- En SQLite las columnas `date` se guardan con hora (`2026-11-22 00:00:00`): `whereBetween` con fechas sueltas deja fuera el último día y `where('fecha', '2026-11-22')` no encuentra nada. Usa rangos semiabiertos o `whereDate()`.

- En macOS, `sed` es la versión BSD (no entiende `\b`). Para reemplazos con regex usa `perl -pi -e`.
- Los tests llaman a `withoutVite()` en `tests/TestCase.php`.
- En `list-grid.js` no uses `this.$root` ni `this.$refs`: según desde dónde se llame el método (después de un `await` o desde un `x-if`), Alpine los deja en `undefined`. Busca los elementos por id (`grid-search`, `grid-create-pres`) o por `data-cell`.
- Al terminar un guardado, la respuesta no debe pisar lo que el usuario siguió escribiendo en esa celda (se compara contra lo que se envió).
- Junto a `x-show`, usa `:style` con un objeto, no con texto: un texto reemplaza todo el atributo `style` y borra el `display: none`.
- En `:disabled="algo.prop"` (expresión con punto) Alpine convierte `undefined` en `""` y el campo queda deshabilitado. Inicializa la propiedad en `false`.
- Las fuentes (Figtree y Bricolage Grotesque) vienen de `@fontsource-variable/*` y se compilan con Vite: `npm run build` ya no necesita internet ni un CDN.
