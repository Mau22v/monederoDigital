# Contrato de integración: App Android ↔ Servidor Banco ↔ Servidor Tienda

| | |
|---|---|
| **Versión** | 1.1 |
| **Fecha** | 2026-11-07 |
| **Responsables** | Mau (servidores Banco y Tienda, Laravel) y [compañero] (app Android, Kotlin) |
| **Ubicación** | `docs/contrato-integracion.md` |

## 1. Propósito y reglas de cambio

Este documento fija **exactamente** cómo se comunican los tres componentes: formatos, algoritmos, cadenas que se firman, endpoints y errores. Si la app y los servidores no coinciden en un solo detalle (un separador, un orden, un parámetro de RSA), las firmas no verifican y los mensajes no se descifran.

Reglas:

1. Lo que está aquí es la **única fuente de verdad**. Si el código y el contrato difieren, se corrige el que esté equivocado y se avisa.
2. Cualquier cambio se acuerda entre los dos, se anota en la sección 17 y sube la versión (1.1, 1.2...).
3. Ningún campo, orden ni separador se "mejora" por cuenta propia.

## 2. Entorno de desarrollo

| Componente | URL local | Desde el emulador de Android |
|---|---|---|
| Servidor Banco | `http://localhost:8001` | `http://10.0.2.2:8001` |
| Servidor Tienda | `http://localhost:8002` | `http://10.0.2.2:8002` |

- Prefijo de todas las rutas: `/api`.
- Para un teléfono físico los servidores se levantan con `php artisan serve --host=0.0.0.0 --port=XXXX` y la app apunta a la IP de la computadora (ver sección 16, pendiente 1).
- En desarrollo se usa **HTTP**. Android bloquea HTTP por defecto: la app debe permitirlo solo en la build de desarrollo (`network_security_config`). Se declara como limitación del prototipo; por eso el contenido sensible va cifrado en la capa de aplicación (sección 6).

## 3. Convenciones generales

| Tema | Regla |
|---|---|
| Formato | JSON, UTF-8. Cabeceras `Content-Type: application/json` y `Accept: application/json` en todas las peticiones. |
| Binarios | **Base64 estándar con relleno** (RFC 4648 §4), **sin saltos de línea**. En Kotlin: `Base64.NO_WRAP`. |
| Tiempo | Enteros: **segundos Unix (UTC)**. Nunca texto de fecha. |
| Nonce | 16 bytes aleatorios en **hexadecimal minúscula** (32 caracteres). De un solo uso. |
| Identificadores | UUID v4 en minúsculas con guiones. Los productos usan enteros. |
| Dinero | **Enteros en pesos**, sin decimales. |
| Denominaciones | Solo 500, 300, 100, 50 y 20. |
| Hash informativo | SHA-256 en hexadecimal minúscula. |
| Nombres de campos | `snake_case`, en español, exactamente como aparecen aquí. |
| Firmas | Los campos se llaman `firma_u` (cliente), `firma_t` (tienda) y `firma_b` (banco). |

## 4. Llaves y dónde viven

Cada servidor tiene **dos pares** de llaves, con funciones distintas.

| Archivo (carpeta `claves-publicas/`, se sube a Git) | Tipo | Para qué se usa |
|---|---|---|
| `banco_rsa_pub.pem` | RSA 2048 | Cifrar mensajes dirigidos al banco (sobre híbrido). |
| `banco_ec_pub.pem` | EC P-256 | Verificar firmas del banco: tickets, `Cert_U`, recibos. |
| `tienda_rsa_pub.pem` | RSA 2048 | Cifrar mensajes dirigidos a la tienda. |
| `tienda_ec_pub.pem` | EC P-256 | Verificar firmas de la tienda: catálogo, pedidos, comprobantes. |

- Las llaves **privadas** viven en `storage/app/keys/` de cada servidor y **nunca** se suben a Git ni se comparten.
- La app incluye las cuatro llaves públicas como archivos (`res/raw`) y **no acepta otras** (llaves fijadas).
- La app **no** tiene llave RSA. Genera un par **EC P-256** en el **Android Keystore** (la privada `Priv_U` no es exportable y se desbloquea con biometría) y envía solo la pública `Pub_U` en el registro.
- Formato de las llaves públicas intercambiadas: **X.509 SubjectPublicKeyInfo en DER, codificado en Base64** (es lo que devuelve `publicKey.encoded` en Android). En archivos `.pem` es el mismo contenido con cabecera `-----BEGIN PUBLIC KEY-----`.

## 5. Algoritmos

| Función | Algoritmo y parámetros |
|---|---|
| Firma digital | **ECDSA P-256 con SHA-256**. La firma va en formato **DER** (ASN.1), en Base64. Android: `SHA256withECDSA`. PHP: `openssl_sign(..., OPENSSL_ALGO_SHA256)`. |
| Cifrado simétrico | **AES-256-GCM**, IV de 12 bytes, etiqueta de 16 bytes. |
| Cifrado asimétrico | **RSA-2048 OAEP**, hash **SHA-256** y MGF1 con **SHA-256**. |
| Contraseñas (servidor) | Argon2id con sal (driver de hash de Laravel). |
| Sesión | JWT **HS256**, vida de 900 s (15 min). Claims: `sub` (id_cuenta), `iat`, `exp`, `jti`. Lo emite solo el banco. |

## 6. Sobre cifrado (cifrado en la capa de aplicación)

Los endpoints marcados "sobre: sí" **envían y reciben** su contenido cifrado así.

### 6.1 Petición

Dentro del sobre va el JSON de la petición, en UTF-8. El sobre es:

```json
{ "k": "<Base64>", "iv": "<Base64>", "ct": "<Base64>" }
```

Quien envía (la app, o la tienda hacia el banco):

1. Genera una llave **K** de 32 bytes aleatorios (`SecureRandom`) y un **IV** de 12 bytes aleatorios. K es nueva en cada mensaje.
2. Cifra el JSON con AES-256-GCM. La salida es `cifrado || etiqueta(16 bytes)` (la etiqueta **pegada al final**).
3. Cifra K con la llave pública RSA del servidor destino (OAEP, SHA-256, MGF1-SHA-256).
4. Envía `k` (K cifrada), `iv` y `ct` (cifrado + etiqueta), todos en Base64.

El servidor: descifra `k` con su llave privada RSA, separa los últimos 16 bytes de `ct` como etiqueta y descifra con AES-GCM. Si algo falla, responde `MENSAJE_CORRUPTO` (sección 12).

### 6.2 Respuesta

La respuesta exitosa se cifra con **la misma K** de la petición y un **IV nuevo** de 12 bytes aleatorios:

```json
{ "iv": "<Base64>", "ct": "<Base64>" }
```

Así la app no necesita llave RSA propia. No se reutiliza nunca un par (K, IV): K cambia en cada petición y el IV de la respuesta es distinto del de la petición.

Las **respuestas de error van en claro** (sección 12) porque pueden ocurrir antes de poder descifrar.

### 6.3 Detalles que suelen fallar

**Kotlin**
- OAEP: usar `Cipher.getInstance("RSA/ECB/OAEPPadding")` con `OAEPParameterSpec("SHA-256", "MGF1", MGF1ParameterSpec.SHA256, PSource.PSpecified.DEFAULT)`. Si se usa solo `OAEPWithSHA-256AndMGF1Padding`, el MGF1 queda con SHA-1 y el servidor no podrá descifrar.
- GCM: `Cipher.getInstance("AES/GCM/NoPadding")` con `GCMParameterSpec(128, iv)`. `doFinal` ya devuelve `cifrado || etiqueta`.
- Firma: `Signature.getInstance("SHA256withECDSA")` entrega DER, que es lo que espera PHP.
- Claves EC en el Keystore: `ECGenParameterSpec("secp256r1")`, propósito `PURPOSE_SIGN`, digest `SHA-256`, `setUserAuthenticationRequired(true)`.
- Leer llaves `.pem`: quitar cabecera y pie, decodificar Base64 y usar `X509EncodedKeySpec` con `KeyFactory` `"EC"` o `"RSA"`.

**PHP**
- OAEP con SHA-256 en ambos hashes: usar **phpseclib** (`withHash('sha256')`, `withMGFHash('sha256')`). La función `openssl_private_decrypt` no permite elegir el hash de MGF1 de forma portable.
- GCM: `openssl_encrypt` devuelve la etiqueta aparte; se concatena al final del cifrado. Para descifrar, separar los últimos 16 bytes.
- Convertir una `pub_u` Base64 a PEM: cabecera `-----BEGIN PUBLIC KEY-----`, el Base64 en líneas de 64 caracteres y pie `-----END PUBLIC KEY-----`.
- `openssl_verify` devuelve `1` (válida), `0` (inválida) o `-1` (error). Solo `1` es aceptable.
- Comparar con `hash_equals`, nunca con `==`.

## 7. Firmas y cadenas canónicas

**La firma NUNCA se calcula sobre el JSON** (el orden de los campos y los espacios cambian entre Kotlin y PHP). Se calcula sobre una **cadena canónica** construida con estas reglas:

1. Texto UTF-8, campos separados por `|`, sin espacios sobrantes ni salto de línea al final.
2. El **primer campo es una etiqueta de tipo** y el segundo es la versión `v1`. Así una firma de un tipo no puede reutilizarse como otro.
3. Enteros en decimal, sin ceros a la izquierda, sin signo y sin decimales.
4. UUID en minúsculas. `pub_u` en Base64 estándar con relleno.
5. Listas separadas por coma, **sin espacios**, ordenadas como se indica.
6. Los nombres de producto y los identificadores no pueden contener `|`, `;`, `:` ni `,` (el servidor los rechaza al crearlos).

Cadenas (cada una se firma con ECDSA P-256 y SHA-256):

```
Ticket            (firma el banco)
  ticket|v1|{id}|{denominacion}|{id_cuenta}|{emision}|{expiracion}

Certificado Cert_U (firma el banco)
  cert|v1|{id_cuenta}|{pub_u}|{expiracion}

Solicitud de tickets (firma el cliente)
  solicitud|v1|{id_cuenta}|{denominaciones}|{nonce}|{timestamp}
  denominaciones: de mayor a menor, "500:2,300:1" (solo cantidades > 0)

Recibo de adquisición (firma el banco)
  recibo_adq|v1|{id_cuenta}|{total}|{ids}|{timestamp}
  ids: UUID de los tickets, orden ascendente (comparación de texto)

Catálogo (firma la tienda)
  catalogo|v1|{timestamp}|{id}:{nombre}:{precio};{id}:{nombre}:{precio}
  productos ordenados por id ascendente

Crear pedido (firma el cliente)
  crear_pedido|v1|{id_cuenta}|{items}|{nonce}|{timestamp}
  items: "{id_producto}:{cantidad}" separados por coma, orden ascendente por id_producto

Pedido (firma la tienda)
  pedido|v1|{id_pedido}|{id_cuenta}|{monto}|{nonce_t}|{expiracion}

Orden de pago (firma el cliente)
  orden|v1|{id_pedido}|{monto}|{ids}|{nonce}|{timestamp}
  ids: UUID de los tickets, orden ascendente

Solicitud de canje (firma la tienda, hacia el banco)
  canje|v1|{id_pedido}|{monto}|{ids}|{nonce}|{timestamp}

Recibo de canje (firma el banco)
  recibo_canje|v1|{id_pedido}|{monto}|{ids}|{timestamp}

Comprobante (firma la tienda)
  comprobante|v1|{id_pedido}|{id_cuenta}|{monto}|{estado}|{timestamp}

Consulta de pedido (firma el cliente)
  consulta_pedido|v1|{id_pedido}|{id_cuenta}|{nonce}|{timestamp}

Consulta de canje (firma la tienda, hacia el banco)
  consulta_canje|v1|{id_pedido}|{nonce}|{timestamp}
```

Ejemplo de ticket (valores de muestra):

```
ticket|v1|3f2b8c1e-5d4a-4e0b-9c7a-1b2c3d4e5f60|500|7a1d9e22-0c3b-4f8a-8b11-aa22bb33cc44|1762500000|1765092000
```

## 8. Objetos que viajan

**Ticket**
```json
{ "id": "uuid", "denominacion": 500, "id_cuenta": "uuid",
  "emision": 1762500000, "expiracion": 1765092000, "firma_b": "<Base64 DER>" }
```

**Cert_U**
```json
{ "id_cuenta": "uuid", "pub_u": "<Base64 X.509>", "expiracion": 1794036000, "firma_b": "<Base64 DER>" }
```

El estado del ticket (`ACTIVO`, `GASTADO`, `EXPIRADO`) **no** viaja en el ticket: solo lo conoce el banco.

## 9. Endpoints del Banco (puerto 8001)

Orden de verificación en todos los endpoints con sobre (sección 13).

### POST `/api/registro`
- Autenticación: ninguna. Sobre: **sí** (RSA del banco).
- Petición (dentro del sobre): `{ "correo": "...", "password": "...", "pub_u": "<Base64 X.509>" }`
- Respuesta 201 (en sobre): `{ "id_cuenta": "uuid", "saldo": 10000, "cert_u": { ... } }`
- Errores: `FORMATO_INVALIDO`, `CORREO_EN_USO` (409).

### POST `/api/login`
- Autenticación: ninguna. Sobre: **sí**.
- Petición: `{ "correo": "...", "password": "..." }`
- Respuesta 200 (en sobre): `{ "token": "<JWT>", "tipo": "Bearer", "expira_en": 900, "id_cuenta": "uuid", "saldo": 10000, "cert_u": { ... } }`
- Errores: `CREDENCIALES_INVALIDAS` (mensaje genérico, no indica si falló el correo o la contraseña), `DEMASIADOS_INTENTOS`.

### POST `/api/cuenta/consulta`
- Autenticación: `Authorization: Bearer <JWT>`. Sobre: **sí**.
- Petición: `{ "nonce": "...", "timestamp": 1762500000 }`
- Respuesta 200 (en sobre): `{ "saldo": 8700 }`

### POST `/api/tickets/solicitar`
- Autenticación: `Bearer <JWT>`. Sobre: **sí**.
- Petición:
```json
{ "id_cuenta": "uuid", "denominaciones": { "500": 2, "300": 1 },
  "nonce": "...", "timestamp": 1762500000, "firma_u": "<Base64 DER>" }
```
- Respuesta 201 (en sobre):
```json
{ "tickets": [ { ...ticket... } ], "saldo": 8700,
  "recibo": { "id_cuenta": "uuid", "total": 1300, "ids": ["uuid"], "timestamp": 1762500001, "firma_b": "..." } }
```
- Errores: `TOKEN_INVALIDO`, `FIRMA_INVALIDA`, `NONCE_REPETIDO`, `TIMESTAMP_FUERA_DE_RANGO`, `SALDO_INSUFICIENTE`, `FORMATO_INVALIDO`.
- La app verifica `firma_b` de cada ticket y del recibo con `banco_ec_pub.pem` antes de guardarlos.

### POST `/api/canje` (lo invoca la **tienda**)
- Autenticación: firma de la tienda. Sobre: **sí** (RSA del banco).
- Petición:
```json
{ "id_pedido": "uuid", "monto": 370, "ids": ["uuid", "uuid", "uuid"],
  "orden": { "nonce": "...", "timestamp": 1762500000, "firma_u": "..." },
  "nonce": "...", "timestamp": 1762500001, "firma_t": "..." }
```
  `orden` son los datos de la orden de pago firmada por el cliente; el banco reconstruye la cadena `orden|v1|...` y verifica `firma_u` con la `Pub_U` del dueño de los tickets.
- Respuesta 200 (en sobre): `{ "recibo": { "id_pedido": "uuid", "monto": 370, "ids": [...], "timestamp": 1762500002, "firma_b": "..." } }`
- **Idempotente:** si el mismo `id_pedido` ya se canjeó con los mismos tickets, devuelve el mismo recibo con 200.
- Errores: `FIRMA_INVALIDA`, `TICKET_GASTADO`, `TICKET_EXPIRADO`, `TICKET_INVALIDO`, `MONTO_INCORRECTO`, `PEDIDO_DUPLICADO` (409, mismo `id_pedido` con tickets distintos), `NONCE_REPETIDO`.

### POST `/api/canjes/consulta` (lo invoca la **tienda**)
- Sobre: **sí**. Petición: `{ "id_pedido": "uuid", "nonce": "...", "timestamp": 1762500000, "firma_t": "..." }`
- Respuesta 200 (en sobre): `{ "recibo": { ... } }`. Si no existe: `CANJE_NO_ENCONTRADO` (404).

## 10. Endpoints de la Tienda (puerto 8002)

### GET `/api/catalogo`
- Autenticación: ninguna. Sobre: **no** (es público, pero va firmado).
- Respuesta 200:
```json
{ "timestamp": 1762500000,
  "productos": [ { "id": 1, "nombre": "Taza", "precio": 370 } ],
  "firma_t": "<Base64 DER>" }
```
- La app verifica `firma_t` con `tienda_ec_pub.pem` antes de mostrar precios.

### POST `/api/pedidos`
- Autenticación: `Cert_U` + `firma_u`. Sobre: **sí** (RSA de la tienda).
- Petición:
```json
{ "cert_u": { ... }, "items": [ { "id": 1, "cantidad": 1 } ],
  "nonce": "...", "timestamp": 1762500000, "firma_u": "..." }
```
- Respuesta 201 (en sobre):
```json
{ "pedido": { "id_pedido": "uuid", "id_cuenta": "uuid", "monto": 370,
              "nonce_t": "...", "expiracion": 1762500600, "firma_t": "..." } }
```
- Errores: `CERTIFICADO_INVALIDO`, `FIRMA_INVALIDA`, `NONCE_REPETIDO`, `FORMATO_INVALIDO`.

### POST `/api/pedidos/{id_pedido}/pago`
- Autenticación: `Cert_U` + `firma_u`. Sobre: **sí**.
- Petición:
```json
{ "cert_u": { ... },
  "orden": { "id_pedido": "uuid", "monto": 370, "tickets": [ { ...ticket... } ],
             "nonce": "...", "timestamp": 1762500100 },
  "firma_u": "..." }
```
  El `id_pedido` de la ruta debe coincidir con el de la orden.
- Respuesta 200 (en sobre):
```json
{ "comprobante": { "id_pedido": "uuid", "id_cuenta": "uuid", "monto": 370,
                   "estado": "PAGADO", "timestamp": 1762500103, "firma_t": "..." },
  "recibo_canje": { ...recibo del banco... } }
```
- Errores: `CERTIFICADO_INVALIDO`, `FIRMA_INVALIDA`, `TICKET_INVALIDO`, `TICKET_GASTADO`, `TICKET_EXPIRADO`, `MONTO_INCORRECTO`, `PEDIDO_EXPIRADO`, `PEDIDO_NO_ENCONTRADO`, `NONCE_REPETIDO`.

### POST `/api/pedidos/{id_pedido}/estado`
- Autenticación: `Cert_U` + `firma_u`. Sobre: **sí**.
- Petición: `{ "cert_u": { ... }, "nonce": "...", "timestamp": 1762500000, "firma_u": "..." }`
- Respuesta 200 (en sobre): `{ "id_pedido": "uuid", "estado": "PAGADO", "comprobante": { ... } }`. El comprobante es `null` si el pedido aún no se pagó.
- Se usa tras una falla de red: la app **no reenvía los tickets**, solo consulta el estado.

Estados de pedido: `CREADO`, `PAGADO`, `RECHAZADO`, `EXPIRADO`.

## 11. Flujo completo en orden

1. `POST /api/registro` al banco (una vez) y `POST /api/login`.
2. `POST /api/tickets/solicitar` al banco. La app verifica y guarda los tickets cifrados.
3. `GET /api/catalogo` a la tienda. La app verifica la firma.
4. `POST /api/pedidos` a la tienda. La app verifica `firma_t` del pedido.
5. `POST /api/pedidos/{id}/pago` a la tienda. La tienda llama a `POST /api/canje` del banco.
6. La app verifica el comprobante y elimina los tickets gastados.
7. Si hubo corte de red en el paso 5: `POST /api/pedidos/{id}/estado`.

## 12. Errores

Formato (siempre **en claro**, sin sobre):

```json
{ "error": { "codigo": "FIRMA_INVALIDA", "mensaje": "Texto breve para el usuario" } }
```

| Código | HTTP | Cuándo | Escenario de prueba |
|---|---|---|---|
| `MENSAJE_CORRUPTO` | 400 | No se puede descifrar o falla la etiqueta de GCM. | **E4** |
| `FORMATO_INVALIDO` | 400 | JSON o campos incorrectos. | |
| `TIMESTAMP_FUERA_DE_RANGO` | 400 | El timestamp difiere más de 120 s del reloj del servidor. | |
| `CREDENCIALES_INVALIDAS` | 401 | Correo o contraseña incorrectos (mensaje genérico). | **E5** |
| `TOKEN_INVALIDO` | 401 | JWT ausente, alterado o vencido. | |
| `FIRMA_INVALIDA` | 403 | Una firma del cliente o de la tienda no verifica. | **E3** |
| `CERTIFICADO_INVALIDO` | 403 | `Cert_U` no verifica, está vencido o no coincide con la cuenta. | |
| `TICKET_INVALIDO` | 403 | La firma del banco en un ticket no verifica (ticket alterado). | **E1** |
| `TICKET_GASTADO` | 409 | El ticket ya fue canjeado. | **E2** |
| `TICKET_EXPIRADO` | 409 | El ticket venció. | |
| `NONCE_REPETIDO` | 409 | El nonce ya se usó (repetición de mensaje). | |
| `PEDIDO_DUPLICADO` | 409 | Mismo `id_pedido` con tickets distintos. | |
| `PEDIDO_EXPIRADO` | 409 | El pedido venció. | |
| `CORREO_EN_USO` | 409 | Correo ya registrado. | |
| `MONTO_INCORRECTO` | 422 | La suma de tickets no es igual al monto (no hay cambio). | |
| `SALDO_INSUFICIENTE` | 422 | Saldo menor al solicitado. | |
| `PEDIDO_NO_ENCONTRADO` | 404 | No existe el pedido. | |
| `CANJE_NO_ENCONTRADO` | 404 | No existe canje para ese pedido. | |
| `DEMASIADOS_INTENTOS` | 429 | Cuenta bloqueada temporalmente tras 5 intentos fallidos. | **E5** |
| `ERROR_INTERNO` | 500 | Falla inesperada. | |

Nota: indicar el bloqueo de cuenta revela que el correo existe. Se acepta en el prototipo porque hace visible el escenario E5 en la demostración; en un sistema real se respondería igual que a una contraseña incorrecta.

## 13. Orden de verificación en los servidores

Para que el mismo ataque produzca siempre el mismo error:

1. Descifrar el sobre → `MENSAJE_CORRUPTO`.
2. Validar el JWT, si el endpoint lo exige → `TOKEN_INVALIDO`.
3. Validar formato y campos → `FORMATO_INVALIDO`.
4. Validar el timestamp (±120 s) → `TIMESTAMP_FUERA_DE_RANGO`.
5. Consultar el nonce (si ya existe) → `NONCE_REPETIDO`.
6. Verificar certificados y firmas → `CERTIFICADO_INVALIDO`, `FIRMA_INVALIDA`, `TICKET_INVALIDO`.
7. **Guardar el nonce** (solo después de que las firmas verificaron, para que un atacante no pueda gastar nonces ajenos).
8. Reglas de negocio: estado de tickets, suma de montos, saldo → `TICKET_GASTADO`, `MONTO_INCORRECTO`, `SALDO_INSUFICIENTE`.

Cada rechazo se registra en la bitácora del servidor con su motivo (se muestra en el panel web).

## 14. Valores y límites

| Concepto | Valor |
|---|---|
| Tolerancia de timestamp | ±120 s |
| Retención de nonces | 15 min como mínimo |
| Vida del JWT | 900 s |
| Intentos fallidos de login | 5, luego bloqueo de 5 min |
| Vigencia de un ticket | 30 días desde la emisión |
| Vigencia de un pedido | 600 s |
| Vigencia de `Cert_U` | 365 días |
| Saldo inicial de una cuenta nueva | $10,000 (simula la cuenta bancaria) |
| Precios de la tienda | Múltiplos de $10, salvo $10 y $30 (se pagan con dinero exacto) |

## 15. Reglas de seguridad para el desarrollo

- No registrar (ni imprimir en logcat o en la consola) llaves privadas, contraseñas ni la K de un sobre.
- Las llaves de prueba y el `.env` no se suben a Git.
- Cada mensaje usa una K nueva y un IV nuevo; nunca fijar valores para "probar" en el código de producción.
- Si algo no verifica, **no se continúa**: se rechaza la operación.

## 16. Decisiones tomadas (antes pendientes)

- [x] **1. Cómo llega la app a los servidores:** durante el desarrollo, la máquina de Mau (servidores en WSL, puertos 8001/8002); el emulador usa `10.0.2.2`. Si se cambia a máquinas independientes, solo cambia la URL base en la app y `BANCO_URL` en la Tienda.
- [x] **2. Saldo inicial:** $10,000 por cuenta nueva.
- [x] **3. Catálogo:** datos de prueba preparados por Mau.
- [x] **4. Vigencias:** las de la sección 14.
- [x] **5. Biometría:** se prueba en teléfonos con huella; el emulador queda para después, sin bloquear el avance.

## 17. Historial de cambios

| Versión | Fecha | Cambio |
|---|---|---|
| 1.0 | 2026-11-07 | Borrador inicial. |
| 1.1 | 2026-11-08 | Sección 16 resuelta; se agrega la sección 18 (endpoints de prueba de criptografía). |

## 18. Endpoints de prueba de criptografía (solo desarrollo)

Existen en **ambos** servidores, únicamente con `APP_ENV=local`. No forman parte del sistema final y se eliminan antes de la entrega.

| Método y ruta | Entrada | Salida | Qué comprueba la app |
|---|---|---|---|
| `POST /api/dev/eco` | Sobre `{k, iv, ct}` con cualquier texto | Respuesta `{iv, ct}` cuyo texto plano es `eco:` + lo enviado | RSA-OAEP y AES-GCM de ida y de vuelta (misma K, IV nuevo) |
| `GET /api/dev/firma` | nada | `{mensaje, firma}` (ticket de ejemplo firmado) | Verificar ECDSA con la llave pública EC pineada del servidor |
| `POST /api/dev/verificar` | `{mensaje, firma, pub_u}` (`pub_u` en Base64 X.509) | `{valida: true/false}` | Que la firma DER de Android la acepta el servidor |

Un sobre alterado en `/api/dev/eco` responde `400 {"error":"MENSAJE_CORRUPTO"}`.