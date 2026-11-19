# PriceTrack: plan para pasar de comparador de precios a app del hogar

Objetivo: además del súper, llevar recetas y menú semanal (tipo Mealie), tareas, los bienes
de la casa con su mantenimiento y su depreciación o plusvalía, y las inversiones, con un
inicio que resuma todo.

## Decisiones ya tomadas por el usuario

- **Orden**: 1) Recetas y menú, 2) Tareas, 3) Bienes y mantenimiento, 4) Inversiones, 5) Inicio.
- **Valores** (inversiones y bienes): captura manual con historial, como los precios.
  Sin cotizaciones automáticas por ahora.
- **Estructura**: la app sigue llamándose PriceTrack; el menú se agrupa en secciones
  **Súper, Cocina, Hogar, Finanzas** y el inicio pasa a ser un resumen de todo.
- Sigue sin login, en español (México), con las convenciones de `AGENTS.md`.

## Cómo trabajar

- Una fase a la vez, cada una con sus tests, `AGENTS.md` y `README.md` al día, y su commit.
- Rama: `claude/wizardly-turing-0llw3c`. No abrir pull request si no se pide.
- Cada módulo sigue lo que ya existe: modelos con `#[Fillable]` y `casts()`, vistas Blade con
  las clases de `app.css`, `<x-combobox>` para texto libre con sugerencias, gráficas con
  `<canvas data-chart>`, reportes en servicios, validación en español.
- No duplicar: las listas de compras ya existen (`shopping_lists`); "listas de compras" del
  pedido original se cubre mandando ingredientes del menú a esas listas.

### Entorno en la nube (sin Docker)

`AGENTS.md` pide Sail, pero en una sesión en la nube no hay Docker. Ahí:

- El PHP del contenedor es 8.3 y `composer.lock` pide 8.4+: correr
  `COMPOSER_ALLOW_SUPERUSER=1 composer update --no-interaction` (tarda varios minutos) y luego
  `git checkout composer.lock`. **No subir cambios de `composer.lock`.** Escribir código
  compatible con PHP 8.3.
- Tests: `mkdir -p tests/Unit && DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test`
  (borrar `tests/Unit` después; hace falta `.env` con `APP_KEY`: `cp .env.example .env &&
  php artisan key:generate`).
- Con SQLite fallan 4 tests que **ya fallaban antes de este trabajo** (la suite está pensada
  para MySQL): `ListGridTest::test_editing_a_cell_registers_a_new_price_for_the_list_date`,
  `PurchaseQuantityTest::test_list_total_and_spending_use_what_you_paid`,
  `PurchaseQuantityTest::test_spending_averages_cover_at_least_a_week_and_explain_what_is_missing`
  y `ReportsTest::test_spending_averages_use_daily_rate`. No se deben romper más; si hay
  MySQL disponible, usarlo.
- `npm ci && npm run build` necesita internet (fonts.bunny.net). `public/build` no se sube.
  Si la red de la sesión no deja llegar a fonts.bunny.net, compilar con una copia temporal de
  `vite.config.js` sin el bloque `fonts` (`npx vite build --config vite.nofonts.config.js`) y
  borrarla después; no subir esa copia.
- Para ver las pantallas: `.env` con `DB_CONNECTION=sqlite` y `DB_DATABASE=/ruta/app.sqlite`,
  `php artisan migrate:fresh --seed && php artisan db:seed --class=DemoDataSeeder`,
  `php artisan serve` y Playwright (escritorio 1280 px y `devices['iPhone 13']`).

## Fase 1. Cocina: recetas y menú semanal ✅ terminada

- Migración `2026_11_19_000001_create_recipes_and_meal_plan_tables.php`:
  `recipes` (nombre, categoría, porciones, minutos, preparación, dirección de origen, notas),
  `recipe_ingredients` (artículo opcional, nombre, cantidad, unidad, nota, posición) y
  `meal_plan_entries` (día, comida, receta o texto libre, porciones).
- Modelos `Recipe`, `RecipeIngredient`, `MealPlanEntry`.
  - Un ingrediente ligado a un artículo lleva cantidad en la **misma dimensión** que el
    artículo (g/kg, ml/L o pza). Guarda también el nombre por si el artículo se elimina.
  - **Costo de la receta** = lo que se usa × el `unit_price` más barato
    (`Product::cheapestPrice()`), no paquetes completos. `Recipe::cost($porciones)` escala.
- `App\Services\MenuShopper`: suma lo que piden las recetas por artículo y lo agrega a una
  lista de compras en presentaciones habituales (250 g de un artículo de 1 kg = 0.25).
  Se **suma** a lo que ya tenga la lista.
- `RecipeController` (CRUD + `POST recipes/{recipe}/shop`) y `MenuController`
  (`GET menu?week=`, `POST menu`, `DELETE menu/{entry}`, `POST menu/shop`). Semana de lunes a domingo.
- Vistas `recipes/*` y `menu/index`; Alpine `recipeForm` (`resources/js/recipe-form.js`).
- Menú por secciones (Inicio · Súper · Cocina) en `layouts/app.blade.php`, desplegables con
  teclado en escritorio y grupos en móvil.
- Tarjeta "Hoy se come" en el inicio; recetas y menú de la semana en `DemoDataSeeder`.
- `tests/Feature/RecipesAndMenuTest.php` (8 tests).

Corregido al revisarla en el navegador y en los tests:

- El **domingo** no salía en el menú: `whereBetween` con la fecha guardada con hora lo
  dejaba fuera. Ahora es rango semiabierto.
- Si alguien cambia la unidad de un artículo (de kg a pza), sus ingredientes ya no se cuestan
  ni se mandan a la lista con una cantidad equivocada (`RecipeIngredient::productBaseQty()`).
- En móvil, las comidas vacías de cada día se volvieron botones ("+ Desayuno", "+ Cena"):
  antes cada día ocupaba casi una pantalla.

Verificado con Playwright (escritorio y iPhone 13): desplegables con clic, flechas y Escape;
menú móvil; agregar/quitar renglones; unidades según el artículo; error de dimensión que
vuelve con lo escrito; escalar porciones; botones + del menú; mandar la semana a una lista y
abrir la hoja. Sin errores de consola ni desbordes horizontales.

### Mejoras posibles de la fase 1 (preguntar antes)

- Importar una receta desde una dirección (JSON-LD `Recipe`), como Mealie.
- Unidades de cocina (taza, cucharada) y "1 cebolla" para artículos por kilo.
- Foto de la receta, favoritos, copiar el menú de otra semana.
- Evitar duplicar al mandar el mismo menú dos veces a la misma lista.

## Fase 2. Hogar: tareas ✅ terminada

- Migración `2026_11_19_000002_create_tasks_tables.php`: `task_lists` (nombre único) →
  `tasks` (título, notas, `due_on`, `completed_at`, prioridad 1–3, `repeat_every` +
  `repeat_unit`, `repeated_from_id`, `taskable` morph opcional para ligar a un bien).
- Modelos `TaskList`, `Task` (`complete()`, `reopen()`, `nextDueOn()`, `status()`).
- `TaskController` (index con grupos Vencidas / Hoy / Próximas / Sin fecha y las hechas en
  7 días; alta rápida; editar; borrar; `POST tasks/{task}/toggle`) y `TaskListController`
  (crear, renombrar, borrar: las tareas pasan a General).
- Vistas `tasks/index`, `tasks/edit`, `tasks/_fields`, `tasks/_row`. Sección **Hogar** en el menú.
- Tareas demo en `DemoDataSeeder`. `tests/Feature/TasksTest.php` (5 tests).
- **Decisión** (era pendiente): una sola regla, **la siguiente fecha se cuenta desde el día
  en que se completa**, para todas las tareas. Es la más simple y la que conviene para
  mantenimiento. Si se quiere "desde el vencimiento" (pagos con día fijo), sería un campo por
  tarea; no se construyó.
- Verificado con Playwright: completar una repetitiva (mensaje con la siguiente fecha y la
  nueva en Próximas), deshacer, alta rápida con "Más" (lista, prioridad, cada año), alta dentro
  de una lista, editar y volver a la lista; en móvil las listas son chips arriba de las tareas.

## Fase 3. Hogar: bienes, mantenimiento y valor ✅ terminada

- Migración `2026_11_19_000003_create_assets_tables.php`: `assets`, `asset_valuations`,
  `meter_readings`, `maintenance_plans` (con `task_id`) y `maintenance_logs` (con `task_id`).
- Modelos `Asset` (valor en una fecha con los tres métodos, ganancia, costo de tenerlo,
  medidor, serie para la gráfica), `AssetValuation`, `MeterReading`, `MaintenancePlan`
  (próximo servicio por tiempo y por uso, estado, `syncTask()`), `MaintenanceLog`.
- Controladores `AssetController` (CRUD), `AssetRecordController` (valores, lecturas y
  servicios), `MaintenancePlanController`; `ReportController::assets` con
  `App\Services\AssetReport`. Vistas `assets/*` y `reports/assets`. Menú Hogar: Tareas,
  Bienes, Reporte de bienes.
- **Enlace con tareas**: cada plan mantiene un recordatorio en la lista "Mantenimiento";
  completarlo registra el servicio y crea el siguiente; reabrirlo lo deshace.
- Bienes demo (auto con km, casa con plusvalía y avalúo, refrigerador con garantía por vencer,
  minisplit, laptop vendida). `tests/Feature/AssetsTest.php` (8 tests).

Decisiones tomadas (las más simples; cambiar si el usuario prefiere otra cosa):

- Con plan por tiempo **y** por uso, toca **lo que pase primero**.
- Sin servicios previos, el próximo se cuenta desde la **compra** del bien (si no hay, desde
  que se creó el plan); por uso, desde la **primera lectura** del medidor.
- El servicio registrado al completar la tarea va **sin costo**; el costo se agrega
  registrando el servicio en la ficha (o se puede borrar y capturar completo).
- "Pronto" = 30 días antes o al 10 % del intervalo de uso. Garantías "por vencer" = 60 días.
- La línea recta usa el **precio de compra** para la pendiente aunque haya capturas: desde una
  captura sigue bajando al mismo ritmo, sin pasar del valor de rescate.
- Un bien vendido o dado de baja vale 0 desde esa fecha (para el patrimonio de la fase 4);
  su ganancia se calcula con lo recibido.
- Sin fotos ni documentos adjuntos (sigue pendiente de decidir).

Encontrado al revisar: un `@endif` pegado a una palabra rompía la ficha (anotado en
`AGENTS.md`); los años del reporte saltaban los que no tenían servicios; la tabla de planes se
apretaba en móvil. Verificado con Playwright: método de valor y estado muestran sus campos,
combobox de categoría, valor por línea recta, plan vencido, completar el recordatorio desde
Tareas registra el servicio, vender quita los recordatorios; sin desbordes en móvil.

## Fase 4. Finanzas: inversiones y patrimonio

- `investment_accounts` (nombre, institución, tipo: CETES, fondo, acciones, cripto, ahorro,
  retiro…; moneda) → `investment_transactions` (fecha, aportación / retiro / dividendo o
  interés / comisión, monto) y `investment_valuations` (fecha, valor), todo manual.
- Si la cuenta no es en pesos, guardar el tipo de cambio que el usuario capture; reportar en MXN.
- Rendimiento: ganancia = valor actual − aportado neto; rendimiento anualizado ponderado por
  dinero (XIRR) con los flujos. Probar el cálculo con casos conocidos.
- **Patrimonio neto**: inversiones + valor de los bienes (fase 3), con su gráfica en el
  tiempo. Dejar previsto, pero preguntar antes de construir, si también se quieren deudas
  (hipoteca, crédito del auto).

## Fase 5. Inicio como resumen

- Tarjetas por sección: gasto del mes e inflación (ya existen), qué se come hoy y costo de la
  semana, tareas de hoy y vencidas, mantenimientos próximos y garantías por vencer,
  patrimonio neto y su cambio.
- Completar el menú por secciones con Hogar y Finanzas, y actualizar el pie de página, el
  texto de bienvenida, `README.md` y `AGENTS.md`.

## Pendientes de decidir con el usuario (no bloquean la fase 1)

- ~~Tareas repetitivas: ¿desde el vencimiento o desde que se completan?~~ Se eligió desde que se completan (fase 2).
- ¿Deudas dentro de Finanzas?
- ¿Avisos fuera de la app (correo, notificación) para tareas y mantenimientos, o basta con verlos en el inicio?
- ¿Fotos y documentos adjuntos (facturas, pólizas, manuales) en los bienes?
