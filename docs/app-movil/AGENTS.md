# PriceTrack App: notas para agentes

App Flutter (Android + iOS) de PriceTrack. El servidor es el repo `maverik23/PriceTrack` (Laravel); esta app solo habla con su API REST en `/api/v1`. La interfaz va en **español (México)**. Antes de programar lee `docs/PLAN.md` (qué fase toca) y `docs/API.md`.

## Decisiones ya tomadas por el usuario (no reabrir)

- **API REST** (no MCP, no WebView). Ya existe en el servidor para Inicio y Súper; las de Cocina, Hogar y Finanzas se agregan en el repo del servidor cuando toque su fase.
- **Android e iOS.** El usuario compila iOS en su Mac.
- **Dirección del servidor configurable** (usa Tailscale) y token opcional. Nada de direcciones fijas en el código.
- **Sin conexión, toda la app se puede ver** (copia local). **Agregar, editar y borrar necesita conexión**, con una sola excepción: en una **lista de compras** se puede agregar sin conexión y se sincroniza al volver. Detalle en `docs/OFFLINE.md`.
- Mismo alcance que la web: las cinco secciones (Inicio, Súper, Cocina, Hogar, Finanzas) con sus altas, cambios y bajas.

## Reglas de arquitectura

- **Los cálculos son del servidor.** El más barato, `cost_for_need`, estimados, totales, ahorro, costos de recetas, patrimonio: todo llega calculado en el JSON. No los repitas en Dart (la web ya tiene una copia en `list-grid.js`; no queremos una tercera). Si a una pantalla le falta un número, se agrega a la API.
- **Después de guardar, se vuelve a pedir** el recurso (la hoja de la lista, la ficha) en vez de recalcular en el teléfono. Puede haber actualización optimista de lo evidente (tachar, cantidad), pero manda la respuesta del servidor.
- **Las reglas de validación son del servidor**: la app muestra los errores 422 (`errors` por campo, ya en español) debajo de cada campo; solo valida en el teléfono lo obvio (vacío, número).
- Toda lectura pasa por el repositorio con copia local (`docs/OFFLINE.md`); ninguna pantalla llama a la red directo para leer.
- Estado con **Riverpod** (API de `Notifier` / `AsyncNotifier`; no `StateNotifier`). HTTP con **dio**. Copia local y cola con **sqflite**. Sin generación de código (ni freezed ni build_runner): modelos escritos a mano con `fromJson`.
- Una carpeta por sección en `lib/features/` (`inicio`, `super`, `cocina`, `hogar`, `finanzas`, además de `connect` y `shell`); lo compartido en `lib/core/` (api, repo, base local, sync, formatos) y `lib/widgets/`.
- Navegación: `Navigator` normal con un `Shell` de cinco pestañas (`NavigationBar` + `IndexedStack`). **No uses go_router** (ver «Flutter» abajo).

## Flutter (verificado el 2026-11-19 con Flutter 3.50.0 / Dart 3.14)

- **Material ya no se importa de `package:flutter/material.dart`** (obsoleto desde 3.47): es `package:material_ui/material_ui.dart`, y `flutter create` ya agrega `material_ui` al `pubspec.yaml`. Son clases distintas a las de `flutter/material.dart`.
- Varios paquetes (go_router 18, fl_chart 1.3) todavía importan `flutter/material.dart`. Por eso: sin go_router; y a fl_chart pásale siempre colores y estilos explícitos (su `Theme.of` no ve el tema de la app).
- Crear el proyecto: `flutter create --org app.pricetrack --project-name pricetrack --platforms android,ios .`
- Paquetes que resolvieron juntos: `flutter_riverpod 3.5`, `dio 5.11`, `sqflite 2.4` (+ `sqflite_common_ffi` en dev para tests), `path`, `path_provider`, `shared_preferences`, `flutter_secure_storage 11`, `mobile_scanner 7.5`, `fl_chart 1.3`, `intl 0.20`, `image_picker 1.2`, `flutter_localizations`.
- `super` es palabra reservada en Dart: la sección Súper se llama `supermercado` en el código (`AppSection.supermercado`).
- Tu conocimiento de Flutter puede estar desactualizado: corre `flutter analyze` seguido y revisa el código fuente del paquete en `~/.pub-cache` antes de suponer una API.

## Plataformas

- **Android**: permiso `INTERNET` y `android:usesCleartextTraffic="true"` (el servidor suele ser `http://` por Tailscale); permiso de cámara para el escáner.
- **iOS**: `NSAppTransportSecurity → NSAllowsArbitraryLoads` (mismo motivo), `NSCameraUsageDescription` y `NSPhotoLibraryUsageDescription` en español.
- El token se guarda con `flutter_secure_storage`; la dirección y el tema, en `shared_preferences`.

## Comandos

```bash
flutter pub get
flutter analyze          # sin avisos antes de cada commit
flutter test             # unitarias y de widgets (corren en Linux, sin dispositivo)
flutter run              # en el dispositivo o emulador del usuario
```

En una sesión en la nube no hay emulador ni Xcode: se puede analizar y probar, no ver la app. El SDK se baja de `storage.googleapis.com/flutter_infra_release` y los paquetes de pub.dev. Lo que no se pudo ver en un dispositivo se dice así en el resumen, no se da por verificado.

## Convenciones

- Textos, comentarios y mensajes de commit en español, como en el servidor. Dinero `$1,234.50`, cantidades sin ceros de más (`1.5`, `345`), fechas `19 nov 2026` (`intl`, `es_MX`).
- Colores: solo la paleta de `lib/theme/tokens.dart` (`context.pal.emerald600`, `context.sec(AppSection.cocina)`). Nada de `Colors.blue` ni hexadecimales sueltos; así claro y oscuro salen solos. Detalle en `docs/DISENO.md`.
- Cada pantalla se revisa en claro y oscuro y en un teléfono angosto (360 px de ancho).
- Tests: la lógica de `core/` (traducción de errores, copia local, cola y su fusión) con unitarias; cada pantalla principal con una prueba de widget contra un `ApiClient` falso alimentado con `docs/ejemplos/*.json`.
- Una fase a la vez, cada una con sus tests y este archivo, `docs/PLAN.md` y `docs/API.md` al día.
- No abrir pull request si no se pide.

## Cuando falte algo en la API

Se agrega en `maverik23/PriceTrack` (`routes/api.php`, `App\Http\Controllers\Api`, `App\Api\Present`), con sus tests en `tests/Feature/ApiTest.php`, reutilizando los modelos, servicios y clases `App\Support\*Data` que ya usan la web y el MCP; luego se documenta en `docs/API.md` de este repo. No se arregla en la app con un cálculo local.
