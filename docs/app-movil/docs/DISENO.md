# Diseño

La app debe sentirse como la web de PriceTrack («papel y jade») hecha para el teléfono, no como Material de fábrica.

## Colores

`design/tokens.dart` (cópialo a `lib/theme/tokens.dart`) trae la paleta de la web ya convertida de oklch a sRGB, en claro y oscuro:

- `Palette.light` / `Palette.dark`: `surface`, `brand`, `brandStrong`, `onBrand`, `sol` y las cuatro familias `slate` (papel y tinta), `emerald` (jade: marca y «lo bueno / barato»), `red` (chile: alertas, subidas) y `amber` (cempasúchil: avisos), del 50 al 950. **En oscuro la escala se invierte** (como en la web), así que `slate900` siempre es «texto» y `slate50` siempre es «fondo».
- `AppSection` (`inicio`, `supermercado`, `cocina`, `hogar`, `finanzas`) con `SectionColors(sec, soft, ink)` por tema: color fuerte, fondo suave y tinta sobre el fondo suave.

Si cambia `resources/css/app.css` en el servidor: `python3 design/tokens.py /ruta/PriceTrack/resources/css/app.css > lib/theme/tokens.dart`.

Reglas (las mismas de la web):

- Fondo de pantalla `slate50`; tarjetas, campos y hojas `surface`, nunca blanco fijo.
- Relleno sólido con texto encima: `brand` + `onBrand` (nunca `emerald600` + blanco: en oscuro no se lee).
- Las pantallas piden colores con una extensión (`context.pal.red600`, `context.sec(AppSection.cocina).soft`); nada de `Colors.*` ni hexadecimales en las vistas.
- Tema automático / claro / oscuro, elegible en Ajustes y guardado.
- Gráficas (fl_chart): colores de la paleta según el tema, pasados explícitamente; series con nombre `jade`, `talavera`, `sol`, `chile`, `ciruela` (= `emerald500`, color de Hogar, `sol`, `red500`, color de Finanzas).

## Tipografía y formas

- **Figtree** para texto y **Bricolage Grotesque** para títulos (títulos de pantalla, de tarjeta y cifras grandes). Incluidas en la app como archivos (`assets/fonts`, pesos 400/500/600/700 de Figtree y 600/700 de Bricolage), no descargadas al vuelo: debe verse igual sin conexión. Ambas son OFL; los `.ttf` estáticos se pueden bajar de Google Fonts.
- Botones de píldora; campos con radio 14; tarjetas con radio 20, borde `slate200` y sin elevación; hojas inferiores con radio 24 arriba.
- Etiquetas pequeñas de color (píldoras): jade = bueno, chile = alerta, cempasúchil = aviso, neutro.
- Iconos de Material redondeados/outlined; el del súper es el carrito.

## Navegación

- Barra inferior con cinco pestañas: **Inicio · Súper · Cocina · Hogar · Finanzas**; el indicador de la pestaña activa usa el `soft` de su sección y el icono su `ink`.
- Cada sección abre en su pantalla principal y tiene arriba pestañas deslizables o un menú con sus pantallas (Súper: Listas · Artículos · Despensa · Precios · Tiendas).
- Detalles y formularios se abren encima (pantalla completa), con regreso.
- Franja de estado arriba de todo cuando aplica: sin conexión (ámbar), enviando cambios, o cambios rechazados (chile).
- Ajustes (engrane en Inicio): servidor y token, copia sin conexión (cuándo se guardó, botón para guardarla, cambios por enviar), apariencia, desconectar.

## Pantallas

### Conexión (primera vez y desde Ajustes)
Dirección del servidor (acepta `mi-servidor:8090` y le pone `http://`; quita `/` final y `/api/v1`), token opcional con mostrar/ocultar, botón «Probar y conectar» (`GET ping`). Mensajes distintos para: no se pudo llegar (menciona Tailscale), no es PriceTrack o no tiene la API (404), token incorrecto (401).

### Inicio
Cuatro cifras en cuadrícula 2×2 con el color de su sección (gasto del súper del mes, costo del menú de la semana, tareas de hoy y vencidas, patrimonio neto y su cambio); cada una lleva a su pestaña. Debajo, solo lo que tenga datos: listas recientes, hoy se come, tareas de hoy y vencidas (se completan ahí mismo cuando exista Hogar), pagos de la semana, mantenimiento, cambios de precio.

### Súper → Listas
Filtro Pendientes / Compradas / Todas con sus conteos, buscador, tarjetas (nombre, fecha, `comprados/total`, total pagado o estimado; las compradas tachadas). Botón «Nueva lista» (nombre sugerido «Despensa 19 nov 2026», fecha, notas, copiar de otra).

### Súper → Hoja de una lista (la pantalla más importante)
No es la tabla tipo Excel de la web. En el teléfono:

- **Encabezado**: total pagado y estimado, avance `comprados/total`, y las tarjetas de ahorro (ahorro contra el más caro, lo que se pudo ahorrar).
- **Agregar**: buscador fijo sobre el catálogo guardado (funciona sin conexión) + botón de **escáner**; si no existe, «Crear “…”» pide presentación (tamaño + unidad) y, con código de barras y conexión, propone los datos de `products/lookup`.
- **Un renglón por artículo**: foto, nombre y marca, lo pedido (`2 × 345 ml = 690 ml`) con − / + y cantidad editable con unidad («610 g»); a la derecha, si está pendiente, el estimado y en qué tienda conviene; si está comprado, tienda, lo comprado y lo pagado, con el nombre tachado. Un círculo a la izquierda marca rápido como comprado **en la tienda que conviene**.
- **Tocar el renglón** abre una hoja inferior con sus tiendas visibles: precio, presentación, $/L·kg·pza, lo que cuesta llevarse lo pedido, ★ el más barato por unidad y «conviene» el de menor costo para esa cantidad; fecha del precio si es viejo. Acciones: marcar comprado ahí, «Compré» (cantidad real), capturar o corregir el precio (acepta a granel), traer el precio en línea (⟳, si hay dirección), agregar otra tienda, ver el artículo, quitar de la lista.
- **Pie**: por tienda, cuántos artículos, la suma y el **ticket** editable; «si compras todo aquí»; total de la lista.
- Menú: editar lista, marcar comprada / reabrir, tiendas visibles, eliminar.
- Sin conexión: ver `docs/OFFLINE.md` §4.

### Súper → Artículos
Buscador (nombre, marca o código; el escáner escribe ahí), filtro por categoría, tarjetas con foto, presentación, el más barato (`$/kg` y tienda) y estado de despensa. Ficha: fotos, datos, el último precio por tienda ordenado del más barato al más caro con el % de ahorro, gráfica de $/unidad en el tiempo por tienda, historial (corregir, borrar), «En cada tienda» (dirección, marca, código; traer precio), despensa (lo tengo / se acabó / cuánto queda), nutrición. Formulario con sugerencias de marca y categoría (`products/options`) y escáner para el código. «Actualizar todos los precios» con su avance (`price-runs`).

### Súper → Despensa
Lo tengo / Se acabó / Todos, buscador y categoría, valor de la despensa. Interruptor por artículo y cuánto queda («300 g»). Al pasar a «se acabó», pregunta si lo agrega a una lista pendiente o nueva (no pregunta si ya está, `in_list`).

### Súper → Precios
Historial con filtros (artículo, tienda, fechas), alta de precio (artículo, tienda, precio, piezas por paquete, contenido, unidad compatible, a granel, fecha, nota; «Traer el precio de hoy» si hay dirección) y corrección.

### Súper → Tiendas
Lista con cuántos precios y el último; alta y edición (nombre, notas, «es súper»), código postal y sucursal por cadena (lista de `settings/branches` o número escrito, según `chains[].list`).

### Cocina, Hogar y Finanzas
Se diseñan al empezar su fase tomando como base la pantalla equivalente de la web (`resources/views` del servidor) y lo descrito en su `AGENTS.md`. Puntos que ya se saben:

- **Cocina**: recetas con porciones ajustables (el servidor manda valores a factor 1 y la app multiplica, único cálculo permitido en el teléfono, igual que `recipe-show.js`); **modo cocinar** a pantalla completa, un paso a la vez, temporizadores por paso, pantalla siempre encendida; menú de la semana un día debajo de otro; revisión antes de mandar ingredientes a la lista.
- **Hogar**: tareas en calendario con el día elegido abajo; mover de fecha con pulsación larga o desde editar (no arrastrar); completar con un toque; bienes con valor, lecturas, servicios y planes.
- **Finanzas**: patrimonio con su gráfica, inversiones, deudas, pagos recurrentes (pagar con monto y lectura) e ingresos.

Mientras una sección no exista en la app, su pestaña muestra «llega en la siguiente etapa» con lo que traerá; no se oculta.
