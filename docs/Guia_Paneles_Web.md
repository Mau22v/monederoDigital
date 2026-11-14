# Guía para quien desarrolla los paneles web

**Objetivo:** dos paneles web de **solo lectura**, uno dentro de cada servidor, para mostrar en la demo qué pasa por dentro (bitácora, cuentas, tickets, canjes, pedidos). **No modifican datos y no cambian la API.**

## 1. Instala y deja funcionando el backend
Sigue `README.md` (raíz del repo), secciones «Instalación», «Llaves privadas» y «Levantar y probar». Comprueba que:
- `cd banco && php artisan test` → 146 en verde; `cd tienda && php artisan test` → 118 en verde.
- Con ambos servidores encendidos, `cd tienda && php artisan cliente:demo` termina con «TODO CORRECTO». **Esto además llena las bases con datos** para que tus pantallas tengan qué mostrar.

Herramientas: WSL/Ubuntu, VS Code (extensiones PHP Intelephense y Laravel Blade), Git, PHP + Composer (ver README). Para los estilos NO necesitas Node: usa CSS simple o una librería por CDN (por ejemplo Bootstrap). Opcional: «DB Browser for SQLite» o la extensión SQLite de VS Code para ver `database/database.sqlite`.

## 2. Dónde trabajar (y dónde NO)
Trabaja **solo** en archivos nuevos:
```
banco/routes/web.php                       ← agrega tus rutas (el prefijo /api es de la API, no lo uses)
banco/app/Http/Controllers/PanelController.php
banco/resources/views/panel/*.blade.php
tienda/routes/web.php
tienda/app/Http/Controllers/PanelController.php
tienda/resources/views/panel/*.blade.php
```
**No toques**: `app/Services/*`, `app/Support/*`, `routes/api.php`, migraciones, modelos, ni `claves-publicas/`. Si necesitas una consulta que los modelos no dan, escríbela en tu controlador usando los modelos existentes y avisa a Mau.
Los paneles se abren en `http://localhost:8001/panel` (banco) y `http://localhost:8002/panel` (tienda); sugerencia, tú decides las rutas.

## 3. Qué mostrar
**Banco** (modelos `App\Models\...`):
- **Bitácora** (`Bitacora`): momento, endpoint, resultado (ACEPTADO/RECHAZADO), código de error, cuenta, detalle. Filtros por resultado y código; los más recientes primero.
- **Cuentas** (`Cuenta`): id, correo, saldo, intentos fallidos, bloqueo.
- **Tickets** (`Ticket`): por cuenta y denominación, estado (`estadoEfectivo($ahora)` da ACTIVO / GASTADO / EXPIRADO), pedido que lo gastó.
- **Canjes** (`Canje`): pedido, cuenta, monto, momento, tickets.
**Tienda:**
- **Bitácora** (`Bitacora`), **Productos** (`Producto`), **Pedidos** (`Pedido`: monto, `estadoEfectivo($ahora)`, vencimiento, tickets usados, pagado en) con sus renglones (`PedidoItem`).
Referencia de qué datos hay y cómo se consultan: los comandos `banco:resumen`, `tienda:resumen` y `bitacora:ver` (carpeta `app/Console/Commands/`). Estructura de tablas: `docs/banco-base-de-datos.md` y `docs/tienda-base-de-datos.md`.

## 4. Reglas de seguridad (se evalúan)
- **Solo lectura:** únicamente `GET`; ningún formulario que escriba datos, ningún botón de borrar.
- **Nunca mostrar secretos:** `password_hash`, llaves, tokens JWT, firmas completas (`firma_b`, `firma_t`, `firma_u`), cuerpos de mensajes. Si hay que mostrar un id, usa los primeros 8 caracteres.
- Escapa todo en Blade con `{{ }}` (nunca `{!! !!}`) para evitar XSS: la bitácora guarda texto que viene de la red.
- Pagina las listas (`->paginate(25)`), no cargues tablas completas.
- Son paneles de demostración sin login; no los expongas fuera de tu red local.
- Tiempos: los datos están en segundos Unix; conviértelos a fecha legible con `\Carbon\Carbon::createFromTimestamp($t)`.

## 5. Cómo probar tu trabajo
1. Levanta ambos servidores y corre `php artisan cliente:demo` (da datos con aciertos y rechazos: E1 a E4).
2. Revisa que la bitácora muestre `TICKET_INVALIDO`, `TICKET_GASTADO`, `FIRMA_INVALIDA`, `MENSAJE_CORRUPTO`, `NONCE_REPETIDO`.
3. Corre `php artisan test` en ambos servidores: **deben seguir en verde** antes de cada commit.
4. Toma capturas (las necesita el reporte técnico y el video de demo).