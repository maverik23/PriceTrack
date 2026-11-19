# Sin conexión

Regla del usuario: **sin conexión se puede ver toda la app; agregar, editar y borrar necesita conexión, salvo en una lista de compras, donde se puede agregar y se sincroniza al volver.**

## 1. Saber si hay conexión

No se usa el estado de la red del teléfono (con Tailscale apagado hay internet pero no servidor). «En línea» = **la última petición al servidor respondió**. El cliente HTTP avisa a un `onlineProvider` (Riverpod) en cada respuesta o fallo de conexión (sin respuesta, tiempo agotado). Estando fuera de línea, un temporizador hace `GET ping` cada ~20 s; al volver: manda la cola y refresca lo que esté en pantalla. También al volver la app al frente.

Tiempo de conexión corto (≈ 6 s) para que sin servidor la app caiga pronto a la copia.

## 2. Copia local (lectura)

SQLite (`sqflite`), tabla `cache(key TEXT PRIMARY KEY, body TEXT, saved_at INTEGER)`: la respuesta JSON de cada dirección de la API tal cual (`key` = ruta con sus parámetros).

Toda pantalla lee por el repositorio, que entrega **primero la copia** (pinta al instante) **y luego la respuesta del servidor**, que reemplaza la copia (`Stream` de 1 o 2 valores; en Riverpod, un `StreamProvider.autoDispose.family` por dirección):

- Con copia y sin conexión: se queda la copia, con el aviso «Sin conexión: ves lo guardado» y «Copia guardada hace 2 h».
- Sin copia y sin conexión: «Sin conexión y esto todavía no se había guardado en el teléfono», con «Reintentar».
- `404`: se borra esa copia.

**Copia completa** para que *toda* la app se vea sin haber abierto antes cada pantalla: al conectar, al abrir la app y cada ~30 min (y con el botón «Guardar copia ahora» de Ajustes) se piden en segundo plano: `dashboard`, `products/catalog`, `products/options`, `pantry`, `providers`, la primera página de `prices`, todos los artículos con `detail=1` (cada uno se guarda además como `products/{id}`), todas las listas (`status=all`) y la hoja de cada lista pendiente y de las últimas 15 compradas. Cada fase nueva agrega aquí sus recursos.

Las listas largas (artículos, listas de compra) se bajan completas y **se buscan y filtran en el teléfono**, así el buscador funciona igual con y sin conexión. El historial de precios sí se pagina en el servidor (sin conexión se ve lo último guardado).

**Fotos**: se piden con el token y se guardan en la carpeta de caché de la app al verlas por primera vez; no se pre-descargan todas.

Al **cambiar de servidor** o desconectar se borran la copia y la cola (eran del otro servidor); se pide confirmación si hay cambios sin enviar.

## 3. Escribir

Con conexión: se manda al servidor, se muestra su mensaje o sus errores y se vuelve a pedir lo que está en pantalla.

Sin conexión, cualquier acción de guardar fuera de la lista avisa «Sin conexión: para … necesitas llegar al servidor» y no hace nada (botones visibles pero sin efecto, o deshabilitados con esa explicación; nunca un fallo silencioso).

## 4. La excepción: lista de compras

Sin conexión, en la hoja de una lista se permite:

| Operación | Se envía como | Por qué es segura de repetir |
|---|---|---|
| **Agregar un artículo** del catálogo guardado, o uno nuevo por nombre + presentación | `POST lists/{id}/items` | Si el artículo ya está, el servidor responde `existing: true` sin duplicar ni cambiar cantidad |
| Cambiar la **cantidad** | `PATCH …/items/{item}` `quantity` | Deja el mismo valor |
| **Marcar o desmarcar** dónde se compró (con un precio ya guardado en la copia) | `PATCH …/items/{item}` `price_id` | Deja el mismo valor |

Agregar es lo que pidió el usuario; cantidad y marcar comprado se incluyen porque son lo que se hace en la tienda y son igual de seguras. **Confirmar con el usuario** si prefiere dejar solo «agregar». Todo lo demás de la hoja (capturar precios, ticket, quitar, «Compré», tiendas visibles) pide conexión.

Cola: tabla `outbox(id INTEGER PRIMARY KEY AUTOINCREMENT, list_id, kind, payload JSON, error, created_at)`, `kind` = `add` | `qty` | `buy`.

- **Fusión al encolar**: cambiar la cantidad de un renglón agregado sin conexión corrige ese `add`; dos `qty` o dos `buy` del mismo renglón dejan solo el último; agregar dos veces el mismo artículo deja uno.
- **Renglones provisionales**: un artículo agregado sin conexión se muestra con id negativo (`-id de la operación`) y la marca «por enviar»; mientras no se sincronice solo admite cambiar cantidad.
- **Mostrar**: la hoja que se pinta es la copia + las operaciones pendientes aplicadas encima (renglón nuevo, cantidad, compra con `subtotal = cost_for_need` de esa celda). Los totales del servidor no se recalculan: se muestran con la nota «se actualizan al sincronizar».
- **Enviar** (al volver la conexión, en orden): éxito → se quita de la cola; fallo de conexión → se detiene y se reintenta después; `404` (la lista o el renglón ya no existen) → se descarta en silencio; otro rechazo (`422`) → se descarta y se avisa al usuario qué no se pudo enviar y por qué. Al terminar se vuelve a pedir la hoja.
- Si una operación con conexión falla *por conexión*, se encola en vez de mostrar error.
- El aviso superior muestra «N cambios por enviar» mientras la cola no esté vacía.

No hay resolución de conflictos más allá de eso: gana lo último que llega al servidor, que para una casa es suficiente.

## 5. Pruebas mínimas

- Repositorio: copia primero y red después; sin red con y sin copia; 404 borra la copia.
- Cola: fusión de operaciones; aplicar pendientes sobre una hoja (`docs/ejemplos/lists_show.json`); envío con éxito, corte de conexión a la mitad (conserva el resto), 404 y 422.
- Widget: la hoja sin conexión deja agregar y marca «por enviar»; un formulario de artículo sin conexión avisa y no envía.
