# Base de datos del Servidor Banco (Fase 4)

SQLite en desarrollo (`banco/database/database.sqlite`). Diagrama: `diagrama-er-banco.png` (fuente: `er.mmd`).

## Tablas

| Tabla | Para qué sirve | Clave y restricciones |
|---|---|---|
| `cuentas` | Cliente, saldo, su `Pub_U`, su `Cert_U` y el control de bloqueo de login | PK `id` (UUID v4); `correo` único |
| `tickets` | Cada ticket emitido, con su firma y su estado | PK `id`; FK a `cuentas`; FK opcional a `canjes`; `estado` solo ACTIVO o GASTADO |
| `canjes` | Un pedido de la tienda pagado con tickets (recibo del banco) | PK `id_pedido`: un pedido no se canjea dos veces |
| `nonces` | Mensajes ya vistos (anti-repetición) | PK `nonce`: la base impide duplicados |
| `bitacora` | Aceptaciones y rechazos con su código de error; alimenta el panel | Sin FK a propósito (también registra intentos contra cuentas inexistentes) |

## Decisiones de diseño

1. **Doble gasto resuelto en la base de datos (E2):** `Ticket::gastar()` hace un solo `UPDATE` que solo toca tickets ACTIVOS, vigentes y de la cuenta indicada, dentro de una transacción. Si no se gastan todos los pedidos, se deshace todo.
2. **Saldo nunca negativo:** `Cuenta::debitar()` descuenta con la condición `saldo >= monto` en la misma instrucción SQL.
3. **Nonce con clave primaria:** si dos peticiones llegan a la vez con el mismo nonce, la base decide cuál llegó primero (`INSERT OR IGNORE`).
4. **EXPIRADO se calcula, no se guarda:** un ticket vence cuando `ahora >= expiracion`. Evita tareas programadas.
5. **UUID v4 explícito:** el contrato exige v4; el `HasUuids` de Laravel genera otra versión.
6. **Sin contraseñas ni llaves privadas en la base:** solo `password_hash` (Argon2id) y llaves públicas.
7. **Las tablas `users`, `cache` y `jobs` que crea Laravel no se usan.**

## Pruebas (`tests/Feature/BaseDatosBancoTest.php`, 24)

Ejecutar: `php artisan test --filter=BaseDatosBancoTest`. Resultado esperado: 24 pasadas, 0 fallos.
