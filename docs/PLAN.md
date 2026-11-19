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
- Para ver las pantallas: `php artisan serve` con SQLite en archivo y Playwright (ya instalado).

## Fase 1. Cocina: recetas y menú semanal (empezada)

### Ya hecho (en esta rama, con un test de flujo que pasa)

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
- `tests/Feature/RecipesAndMenuTest.php`.

### Falta

1. **Menú por secciones** en `layouts/app.blade.php`: Inicio · Súper (Listas, Artículos,
   Proveedores, Precios y los tres reportes) · Cocina (Recetas, Menú). Desplegables con
   Alpine que funcionen en móvil y con teclado. Hoy las pantallas nuevas no tienen enlace.
2. **Revisar las pantallas en un navegador** (nadie las ha visto todavía): formulario de
   receta (agregar/quitar renglones, unidades según el artículo, volver con errores de
   validación conservando lo escrito), ficha de receta, menú en móvil y escritorio.
3. Compilar con `npm run build` y pasar `pint`.
4. Más tests: validación de dimensión y de cantidad obligatoria, edición que reemplaza
   ingredientes, artículo eliminado (el ingrediente queda como texto), semana con varias
   recetas que comparten artículo, `?week=` inválido.
5. Recetas de ejemplo en `DemoDataSeeder` (arroz, frijoles, huevo, tortilla ya están en el catálogo).
6. Tarjeta "Hoy se come" en el inicio.
7. Documentar en `AGENTS.md` (sección Dominio) y `README.md`.

### Mejoras posibles de esta fase (después de lo anterior, preguntar antes)

- Importar una receta desde una dirección (JSON-LD `Recipe`), como Mealie.
- Unidades de cocina (taza, cucharada) y "1 cebolla" para artículos por kilo.
- Foto de la receta, favoritos, copiar el menú de otra semana.
- Evitar duplicar al mandar el mismo menú dos veces a la misma lista.

## Fase 2. Hogar: tareas

- `task_lists` (nombre) → `tasks` (título, notas, `due_on` opcional, `completed_at`,
  prioridad, repetición: cada N días/semanas/meses/años).
- Al completar una tarea repetitiva se crea la siguiente. Decidir y documentar si la
  siguiente fecha cuenta desde el vencimiento o desde el día en que se completó
  (para mantenimiento suele convenir desde que se hizo).
- Vistas: Hoy / Próximas / Vencidas / Sin fecha, por lista; completar con un clic.
- Las tareas pueden apuntar opcionalmente a otra cosa (un bien, en la fase 3) para que el
  mantenimiento no necesite otro sistema de recordatorios.

## Fase 3. Hogar: bienes, mantenimiento y valor

- `assets`: nombre, categoría (combobox), marca/modelo, número de serie, ubicación,
  fecha y precio de compra, garantía hasta, notas, estado (en uso, vendido, dado de baja
  con fecha y monto de venta).
- **Valor**: `asset_valuations` (fecha, valor), captura manual con historial, y un método
  de estimación por bien para los días sin captura:
  - ninguno (vale la última captura),
  - línea recta (vida útil en años + valor de rescate),
  - porcentaje anual (negativo = depreciación, positivo = plusvalía; para casa o terreno).
  El valor de hoy = última captura, ajustada con el método desde esa fecha. Gráfica del valor
  en el tiempo y ganancia/pérdida contra el precio de compra.
- **Mantenimiento**: `maintenance_plans` por bien (qué, cada cuánto: por tiempo y/o por uso,
  por ejemplo cada 6 meses o 10,000 km; costo estimado) y `maintenance_logs` (fecha, lectura
  del medidor, costo, proveedor, notas). El bien puede tener un medidor (km, horas) con
  lecturas. De ahí sale "próximo servicio" y lo vencido; enlazar con las tareas de la fase 2.
- Reportes: costo de mantenimiento por bien y por año, costo total de tener cada bien
  (compra + mantenimiento − valor actual), garantías por vencer.

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

- Tareas repetitivas: ¿desde la fecha de vencimiento o desde que se completan?
- ¿Deudas dentro de Finanzas?
- ¿Avisos fuera de la app (correo, notificación) para tareas y mantenimientos, o basta con verlos en el inicio?
- ¿Fotos y documentos adjuntos (facturas, pólizas, manuales) en los bienes?
