# PriceTrack: notas para agentes

Comparador personal de precios del súper. Laravel 13 + Sail (PHP 8.5, MySQL 8.4), Blade + Tailwind v4 + Chart.js. Sin login. La interfaz está en español (México).

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
- **Estimados y comparación por tienda**: cantidad × presentación habitual × precio por unidad (`Product::standardBaseQty()`), nunca cantidad × precio del paquete.
- **Gasto**: solo los items con `price_id`, en la fecha de la lista. Los promedios salen del gasto por día del periodo real.
- **Inflación**: índice mensual encadenado (media geométrica) por par artículo + proveedor, arrastrando el último precio conocido.
- Los reportes viven en `app/Services/ReportService.php`.
- **Hoja de la lista** (`lists/show`): tabla tipo Excel en Alpine (`resources/js/list-grid.js`) alimentada por `App\Support\ListGrid`. Filas = artículos, columnas = proveedores, celdas = último precio. Editar una celda hace `POST lists/{list}/items/{item}/prices`, que registra el precio con `ShoppingList::priceDate()` (la fecha de la lista, nunca futura). Si ese día ya se capturó desde la misma lista, corrige ese registro; si no, crea uno nuevo y conserva el historial. Si la compra estaba en ese proveedor, pasa al precio nuevo. Los cálculos en vivo del JS replican los de los modelos: si cambias uno, cambia el otro.

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

- En macOS, `sed` es la versión BSD (no entiende `\b`). Para reemplazos con regex usa `perl -pi -e`.
- Los tests llaman a `withoutVite()` en `tests/TestCase.php`.
- En `list-grid.js` no uses `this.$root` ni `this.$refs`: según desde dónde se llame el método (después de un `await` o desde un `x-if`), Alpine los deja en `undefined`. Busca los elementos por id (`grid-search`, `grid-create-pres`) o por `data-cell`.
- Al terminar un guardado, la respuesta no debe pisar lo que el usuario siguió escribiendo en esa celda (se compara contra lo que se envió).
