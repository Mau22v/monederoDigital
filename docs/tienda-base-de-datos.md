# Base de datos del Servidor Tienda (Fase 7)

SQLite en desarrollo (`tienda/database/database.sqlite`). Diagrama: `diagrama-er-tienda.png` (fuente: `er.mmd`).

## Tablas

| Tabla | Para qué sirve | Clave y restricciones |
|---|---|---|
| `productos` | Catálogo | PK `id` entero fijado por quien prepara el catálogo; el modelo rechaza precios no pagables y nombres con `\| ; : ,` |
| `pedidos` | Pedido firmado por la tienda | PK `id` (UUID v4); `estado` solo CREADO, PAGADO o RECHAZADO; `id_cuenta` sin FK (la cuenta vive en el banco) |
| `pedido_items` | Renglones del pedido | FK a `pedidos` con borrado en cascada; único (`id_pedido`, `id_producto`); guarda COPIA de nombre y precio |
| `nonces` | Mensajes ya vistos | PK `nonce` |
| `bitacora` | Aceptaciones y rechazos | Sin FK; sin secretos |

## Decisiones de diseño

1. **Sin cuentas ni JWT:** la tienda confía en el `Cert_U` firmado por el banco.
2. **Monto calculado en el servidor** con los precios de `productos`.
3. **Copia de nombre y precio** en `pedido_items`: un cambio posterior de precio no altera pedidos firmados.
4. **Precio pagable con dinero exacto:** múltiplo de 10, mínimo 20, distinto de 30, máximo 10,000 (comprobado por una prueba que recorre todos los valores).
5. **EXPIRADO se calcula** (`ahora >= expiracion`), no se guarda.
6. **Un pedido ajeno es indistinguible de uno inexistente.**
7. Las tablas `users`, `cache` y `jobs` de Laravel no se usan.

## Pruebas

`php artisan test --filter=BaseDatosTiendaTest` (15), `CatalogoTest` (10), `PedidosTest` (33), `EstadoPedidoTest` (10).
