# Plan

Objetivo: una app Flutter (Android + iOS) con la misma información que la web de PriceTrack y sus altas, cambios y bajas. Decisiones del usuario y reglas en `AGENTS.md`; contrato de la API en `docs/API.md`.

## Cómo trabajar

- Una fase a la vez, en orden. Cada fase termina con `flutter analyze` limpio, `flutter test` en verde, docs al día y su commit.
- Lo que requiere dispositivo (cámara, compilar iOS, verse bien) lo prueba el usuario: al cerrar cada fase, deja una lista corta de qué probar en el teléfono.
- La API de todas las fases ya existe en el servidor (`maverik23/PriceTrack`, `main`): ver `docs/API.md`. Si a una pantalla le falta algo, se agrega primero en el servidor (con tests) y luego en la app.

## Fase 0. Servidor: API de Inicio y Súper ✅ hecha

En `maverik23/PriceTrack` (`main`): `/api/v1` con token opcional (`API_ENABLED`, `API_TOKEN`), inicio, tiendas, artículos, despensa, precios, precios en línea y listas con la hoja calculada (`App\Api\ListSheet`). Tests en `tests/Feature/ApiTest.php`.

**API de Cocina, Hogar y Finanzas ✅ hecha** (mismo repo, `main`): recetas, menú, envío a la lista, tareas y calendario, bienes, inversiones, patrimonio, deudas, pagos recurrentes, ingresos y reportes del súper. Tests en `ApiKitchenTest`, `ApiHomeTest` y `ApiFinanceTest`; ejemplos en `docs/ejemplos/`.

- [ ] Confirmar con el usuario que `main` del servidor está desplegado (si no, la app no tiene contra qué probar).

## Fase 1. Esqueleto, conexión, Inicio y Súper

1. **Proyecto**: `flutter create` (ver `AGENTS.md`), paquetes, fuentes en `assets/fonts`, `tokens.dart`, tema claro/oscuro, permisos de Android e iOS, nombre «PriceTrack» e icono (carrito/canasta sobre jade).
2. **Núcleo** (`lib/core`): ajustes (dirección, token, tema), cliente de la API con traducción de errores (sin conexión / 401 / 404 / 422 por campo / 409), base local, repositorio con copia local, estado «en línea», sincronización (cola de listas + copia completa), formatos (`money`, `qty`, `pct`, fechas).
3. **Conexión** y **Ajustes**.
4. **Estructura**: cinco pestañas, franja de estado, «llega en la siguiente etapa» para Cocina, Hogar y Finanzas.
5. **Inicio**.
6. **Súper**: listas, hoja de la lista (con lo de sin conexión), artículos (lista, ficha con gráfica, formulario, fotos, escáner), despensa, precios, tiendas, precios en línea (por enlace y «Actualizar todos»).
7. **Tests** (ver `AGENTS.md` y `docs/OFFLINE.md` §5).

Terminado cuando: con un servidor con datos demo (`php artisan db:seed --class=DemoDataSeeder`) se puede hacer desde el teléfono todo lo que la web permite en Súper; en modo avión se ven Inicio y todo Súper, se agrega a una lista y al volver la conexión aparece en la web.

Orden sugerido dentro de la fase (cada punto deja la app usable): 1–4 → Inicio → listas y hoja → artículos → despensa → precios y tiendas → precios en línea.

## Fase 2. Cocina

Servidor: ✅ (`docs/API.md` «Cocina»). App: recetas (lista con filtros de despensa y etiquetas, ficha con porciones ajustables, costo y macros, calificación, fotos, formulario con secciones, sub-recetas y pasos por etapa), modo cocinar, menú semanal con notas, revisión y envío de ingredientes a una lista. Copia sin conexión: recetas completas y la semana actual y la siguiente (`docs/OFFLINE.md` §2).

## Fase 3. Hogar

Servidor: ✅ (`docs/API.md` «Hogar»). App: tareas (calendario y lista, alta rápida, repetición, completar/reabrir, mover, listas), bienes (ficha con valor y gráfica, lecturas, servicios, planes, fotos y documentos), reporte de bienes. El inicio ya puede completar tareas.

## Fase 4. Finanzas y reportes

Servidor: ✅ (`docs/API.md` «Finanzas» y «Reportes del súper»). App: esas pantallas con sus gráficas y registros.

## Fase 5. Pulido

Notificaciones locales (tareas y pagos por vencer), acceso rápido a la lista pendiente, revisión de accesibilidad y tamaños de letra. **No se publica en tiendas**: el usuario la instala directo en sus teléfonos (Android: `flutter build apk --release` e instalar el APK; iOS: desde Xcode en su Mac con su cuenta de Apple), así que no hacen falta fichas de tienda ni capturas. Dejar en el `README.md` los pasos para compilar e instalar en cada plataforma.

## Decisiones confirmadas por el usuario (2026-11-19)

- Sin conexión, en una lista de compras se puede **agregar, cambiar la cantidad y marcar o desmarcar lo comprado** (`docs/OFFLINE.md` §4).
- Distribución: **solo instalada en sus teléfonos**, sin tiendas.

## Preguntas abiertas para el usuario

1. ¿Identificador de la app? Propuesto `app.pricetrack.pricetrack`.
