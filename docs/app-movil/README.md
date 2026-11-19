# PriceTrack App

App móvil (Flutter, Android e iOS) de [PriceTrack](https://github.com/maverik23/PriceTrack), la app del hogar: súper, cocina, hogar y finanzas. Muestra lo mismo que la web y permite agregar, editar y borrar, hablando con la API REST del servidor (`/api/v1`).

**Estado: por implementar.** Este repositorio arranca con el plan y las especificaciones; todavía no hay código de la app.

| Documento | Para qué |
|---|---|
| [`AGENTS.md`](AGENTS.md) | Reglas y convenciones para quien programe aquí (leer primero) |
| [`docs/PLAN.md`](docs/PLAN.md) | Fases, tareas y criterios de terminado |
| [`docs/API.md`](docs/API.md) | Contrato de la API (`/api/v1`): lo que ya existe y lo que falta |
| [`docs/OFFLINE.md`](docs/OFFLINE.md) | Cómo funciona sin conexión: copia local y cola de las listas |
| [`docs/DISENO.md`](docs/DISENO.md) | Tema «papel y jade», navegación y pantallas |
| [`docs/ejemplos/`](docs/ejemplos) | Respuestas reales de la API (recortadas) para modelos y tests |
| [`design/tokens.dart`](design/tokens.dart) | Colores del tema ya convertidos a Flutter |

## Conexión

La app no trae servidor fijo: al abrirla por primera vez pide la **dirección** de tu PriceTrack (por ejemplo la de Tailscale, `http://mi-servidor:8090`) y, si el servidor define `API_TOKEN`, el **token**. Se puede cambiar en Ajustes.
