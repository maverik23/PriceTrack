# PriceTrack: comparador de precios del súper y lista de compras

**PriceTrack** es una app web *self-hosted* para **comparar precios del supermercado** entre tiendas (Sam's Club, Costco, Walmart, Amazon, Soriana, Chedraui o las que agregues), calcular el **precio por unidad** ($/L, $/kg, $/pza) y saber **dónde conviene comprar**. Llevas tu **lista de compras como una hoja de Excel**, cada precio queda guardado con su fecha y su tienda, y con ese historial ves tu **inflación personal** y tu **gasto promedio semanal, mensual y anual**.

Hecha con Laravel 13, Alpine.js y Tailwind CSS. Corre en Docker (Portainer, servidor casero o VPS), sin cuentas ni login, y tus datos se quedan en tu servidor.

> 🇬🇧 **English:** self-hosted **grocery price tracker** and **shopping list**. Compare supermarket prices across stores by **unit price** (per liter, kilo or piece), log every price with its date and store, and get your **personal inflation** and **average grocery spending** (weekly, monthly, yearly). Spreadsheet-like shopping list, no login, runs on Docker. The UI is in Spanish (Mexico).

## Funciones

- **Precio por unidad automático.** Compara presentaciones distintas: `345 ml` contra `1 L`, o el paquete de club `6 × 1 L` contra la pieza suelta. Siempre ves cuál es el **★ más barato** de verdad.
- **Lista de compras tipo Excel.** Filas = artículos, columnas = tiendas.
  - Escribe el nombre del artículo y se autocompleta. Al elegirlo se cargan sus **últimos precios** en cada tienda.
  - Cambia un precio, presiona `Tab` o `Enter` y queda registrado como **precio nuevo con la fecha de la lista**. El historial anterior se conserva.
  - La presentación se escribe tal cual: `6 x 1 L`, `2 x 680 g`, `30 pza`. Si se vende **a granel** (fruta, tortilla, salchichonería) escribe el precio como `54/kg` o `25 por 100 g`.
  - En **Cant.** pones lo que necesitas: `2` (presentaciones) o el contenido, `610 g`. Lo pagado se calcula con los **paquetes completos** que vende cada tienda, o lo justo si es a granel (610 g a $54/kg = $32.94).
  - Cada tarjeta muestra el **total** de lo que necesitas en esa tienda (ej. 4 paquetes de 18 L = $376.48). El ★ marca el más barato por unidad y el total en verde, dónde sale más barato llevarte lo que necesitas: si necesitas 1 kg y Sam's solo vende 2 kg, quizá conviene Walmart.
  - Si el artículo no existe se crea en el momento, y la presentación se toma del nombre ("Salsa de tomate 345 ml").
  - Marca **dónde lo compraste** y ve cuánto costaría la lista completa en cada tienda y cuánto ahorraste: el ahorro **real** (con los paquetes que hay que comprar) y el ahorro **por unidad**.
  - Copia los artículos de una lista anterior para la despensa de cada semana.
- **Código de barras.** Escanéalo con la cámara del celular (o con un lector USB/Bluetooth) para buscar el artículo, abrirlo o agregarlo a la lista.
  - Si el código no está en tus artículos se buscan sus datos en internet ([Open Food Facts](https://world.openfoodfacts.org) y, si ahí no está, UPCitemdb) y se autocompleta nombre, marca, categoría y presentación.
  - La cámara en vivo necesita que entres por **HTTPS** (o desde `localhost`). Por `http://` en la red de tu casa puedes tomar una foto del código y se lee igual.
- **Historial de precios** por artículo y tienda, con gráfica del precio por unidad en el tiempo.
- **Reportes:**
  - **Gasto promedio** semanal, mensual y anual, con desglose por mes, semana, año, tienda y categoría.
  - **Inflación de tu canasta**: índice mensual (base 100), inflación anualizada y variación por artículo.
  - **Cambios de precio**: cada subida o bajada contra el registro anterior del mismo artículo en la misma tienda.
- **Marcas y categorías con autocompletar**, sin duplicados ("lacteos" se une con "Lácteos").
- Interfaz en español (México), responsiva para usarla en el celular dentro de la tienda.

## Instalar con Docker (self-hosted)

Necesitas Docker con Compose. La imagen usa FrankenPHP (PHP 8.5) y MySQL 8.4.

```bash
git clone https://github.com/maverik23/PriceTrack.git
cd PriceTrack
docker compose -f docker-compose.prod.yml up -d --build
```

Abre `http://<tu-servidor>:8090`. En el primer arranque se crean las tablas y las tiendas por defecto (Sam's Club, Costco, Walmart y Amazon).

Variables opcionales (en un `.env` junto al compose, o en Portainer):

| Variable | Default | Para qué |
|---|---|---|
| `PRICETRACK_PORT` | `8090` | Puerto publicado |
| `APP_URL` | `http://localhost:8090` | URL con la que entras |
| `APP_TIMEZONE` | `America/Mexico_City` | Zona horaria de las fechas |
| `DB_PASSWORD` | `pricetrack` | Contraseña de MySQL (cámbiala si el servidor es accesible desde fuera) |
| `APP_KEY` | *(se genera sola)* | Si no la defines, se genera una vez y se guarda en el volumen |
| `BARCODE_LOOKUP` | `true` | `false` para no consultar internet al buscar un código de barras |

### Portainer

1. **Stacks → Add stack → Repository**.
2. Repository URL: `https://github.com/maverik23/PriceTrack`. Compose path: `docker-compose.prod.yml`.
3. (Opcional) agrega las variables de la tabla en *Environment variables*.
4. **Deploy the stack**. Portainer construye la imagen y levanta la app y la base de datos.

Para actualizar: en el stack, **Pull and redeploy**. Tus datos viven en los volúmenes `storage` y `db`.

## Desarrollo (Laravel Sail)

```bash
cp .env.example .env
# Instala dependencias PHP sin tener PHP local
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd)":/var/www/html -w /var/www/html \
  laravelsail/php84-composer:latest composer install --ignore-platform-reqs
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install && ./vendor/bin/sail npm run build
```

Abre http://localhost:8080 (puerto en `APP_PORT`). Para recarga en caliente: `./vendor/bin/sail npm run dev`.

```bash
# Base limpia (solo tiendas)
./vendor/bin/sail artisan migrate:fresh --seed
# Un año de datos de ejemplo (52 listas, ~1,100 precios)
./vendor/bin/sail artisan db:seed --class=DemoDataSeeder
# Tests
./vendor/bin/sail artisan test
```

## Cómo se calcula

- **Precio por unidad** = precio ÷ (piezas × contenido convertido a ml, g o pza). Se muestra en $/L, $/kg o $/pza.
- **Más barato** = el menor precio por unidad entre el **último** precio registrado en cada tienda.
- **Total por tienda y estimados** = lo que pagarías por lo que necesitas con los paquetes de cada tienda (o lo justo a granel). Lo pendiente se estima en la tienda más barata para esa cantidad.
- **Lo pagado** = los paquetes completos que cubren lo que necesitas (si te falta menos de 15 % de un paquete no se cuenta otro: 946 ml ≈ 1 L, el bloque de 907 g ≈ 1 kg), o lo justo si se vende a granel.
- **Gasto** = lo pagado en los artículos marcados como comprados, en la fecha de la lista. Los promedios usan el gasto por día del periodo real (mínimo una semana).
- **Ahorro** = *real*: lo que te costaba lo mismo en la tienda más cara, con sus paquetes, menos lo que pagaste; *por unidad*: la diferencia de $/kg, $/L o $/pza por lo que te llevaste.
- **Inflación** = por cada artículo en cada tienda se toma el precio unitario promedio de cada mes, arrastrando el último precio conocido. Luego se encadena la media geométrica de las variaciones mes a mes.

## Código

- `app/Enums/Unit.php`: unidades y conversiones.
- `app/Models/Price.php`: normaliza `base_qty` y `unit_price` al guardar.
- `app/Services/ReportService.php`: gasto, inflación y cambios de precio.
- `app/Support/ListGrid.php` + `resources/js/list-grid.js`: la hoja de la lista de compras.
- `Dockerfile`, `docker-compose.prod.yml`, `docker/`: imagen y stack de producción.

PriceTrack no está afiliado a ninguna de las tiendas mencionadas; los nombres se usan solo como ejemplo.

## Licencia

[MIT](LICENSE)
