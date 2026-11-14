# Guía para quien desarrolla la app Android (billetera)

La app es el **cliente**: genera su llave, se registra en el Banco, compra tickets, crea pedidos en la Tienda y paga con tickets. Todo el protocolo está en **`docs/contrato-integracion.md` (v1.5)**; aquí está el orden de trabajo y las trampas comunes.

## 1. Preparación
1. Instala **Android Studio** (Kotlin). Crea un emulador con API 29+ (el Keystore con biometría necesita huella/PIN configurados en el emulador).
2. Necesitas los servidores corriendo: ver `README.md` (puedes levantarlos tú, o pedir a Mau que los encienda y usar su IP). Emulador → `http://10.0.2.2:8001` (Banco) y `http://10.0.2.2:8002` (Tienda). Teléfono físico: IP de la PC, ambos en la misma red, servidores con `--host=0.0.0.0`.
3. HTTP sin TLS en desarrollo: añade `res/xml/network_security_config.xml` permitiendo tráfico en claro **solo en la build debug** a esas direcciones, y referéncialo en el `AndroidManifest`. Permiso `INTERNET`.
4. Copia las 4 llaves públicas de `claves-publicas/` a `res/raw/` (`banco_rsa_pub.pem`, `banco_ec_pub.pem`, `tienda_rsa_pub.pem`, `tienda_ec_pub.pem`). La app **no acepta otras** (llaves fijadas).
5. **Reloj:** el servidor rechaza mensajes con más de ±120 s de diferencia. Hora automática en el emulador/teléfono.

## 2. Criptografía en Android (lo que casi siempre falla)
| Tema | Qué hacer |
|---|---|
| Llave de la app | Par **EC P-256** en **Android Keystore** (`KeyPairGenerator "EC"`, `secp256r1`, `PURPOSE_SIGN`, `setUserAuthenticationRequired(true)` para biometría). La privada no sale del Keystore. Se envía `publicKey.encoded` en Base64 (`Base64.NO_WRAP`) como `pub_u`. |
| Firmar | `Signature.getInstance("SHA256withECDSA")` → firma en **DER** (es lo que da Java) → Base64 `NO_WRAP`. |
| Verificar firmas del servidor | Con las llaves públicas EC de `res/raw`, `SHA256withECDSA`, firma DER en Base64. Verifica **siempre**: tickets, Cert_U, recibos, comprobantes, catálogo y pedidos. |
| Cadenas que se firman | Texto exacto `tipo|v1|campo|campo...` (sección 7 del contrato). **No es JSON.** Enteros sin formato; listas de ids ordenadas de forma ascendente por texto, separadas por coma. Un carácter distinto = `FIRMA_INVALIDA`. |
| Sobre cifrado | Por cada petición: genera `K` (AES-256 aleatoria) e `iv` (12 bytes), cifra el JSON con **AES-256-GCM** (tag 16 bytes pegado al final del `ct`), y cifra `K` con la **RSA pública del servidor destino** usando **RSA-OAEP con SHA-256 y MGF1 SHA-256**. Envía `{k, iv, ct}` en Base64. |
| ⚠ OAEP en Android | `Cipher.getInstance("RSA/ECB/OAEPWithSHA-256AndMGF1Padding")` usa **MGF1 con SHA-1** por defecto. Fuerza los parámetros: `OAEPParameterSpec("SHA-256", "MGF1", MGF1ParameterSpec.SHA256, PSource.PSpecified.DEFAULT)`. Si no, el servidor responde `MENSAJE_CORRUPTO`. |
| Respuesta | Las de éxito vienen como `{iv, ct}` cifradas con **la misma K** y un iv nuevo: descífralas con esa K. Los **errores vienen en claro**: `{"error":{"codigo","mensaje"}}`. |
| Nonce | 16 bytes de `SecureRandom` en **hexadecimal minúscula** (32 caracteres), nuevo por mensaje. |
| Tiempo | `System.currentTimeMillis() / 1000` (entero). |
| Base64 | Estándar con relleno y **sin saltos de línea** (`Base64.NO_WRAP`). |
| JSON | Números como números (no texto). `monto`, `cantidad`, `timestamp` son enteros. |

Referencia ejecutable: `tienda/app/Console/Commands/ClienteDemo.php` hace exactamente este flujo en PHP (firmar, sobre, pagar). Los vectores de prueba de cadenas y sobres están en `banco/tests/Unit/CryptoServiceTest.php`. **Antes de hacer pantallas, implementa y prueba la capa criptográfica** contra el servidor con una pantalla de depuración.

## 3. Orden de desarrollo recomendado
1. **Capa criptográfica** (hash, firma, verificación, sobre) con pruebas unitarias.
2. **Registro** (`/api/registro` del Banco) → guarda `Cert_U` y `id_cuenta` (en `EncryptedSharedPreferences`).
3. **Login** (`/api/login`) → token JWT (dura 15 min; renueva con nuevo login al recibir `TOKEN_INVALIDO`). Se envía como `Authorization: Bearer <token>` y **también** la petición va cifrada.
4. **Saldo** (`/api/cuenta/consulta`).
5. **Comprar tickets** (`/api/tickets/solicitar`): elige cuántos de cada denominación; verifica la firma del banco de cada ticket recibido y **guárdalos cifrados** en el teléfono (son dinero).
6. **Catálogo** (`GET /api/catalogo`, verifica `firma_t`).
7. **Crear pedido** (`/api/pedidos` de la Tienda) → verifica la firma del pedido.
8. **Pagar** (`/api/pedidos/{id}/pago`): **elige tickets que sumen exactamente el monto** (la tienda NO da cambio; si no hay combinación exacta, mensaje al usuario para comprar otras denominaciones), firma la orden y envía; verifica `comprobante.firma_t` y `recibo_canje.firma_b`. Marca esos tickets como gastados localmente.
9. **Estado** (`/api/pedidos/{id}/estado`).
10. Pantallas: registro/login, saldo, comprar tickets, catálogo, carrito, pago, comprobante, historial. Biometría para desbloquear la firma.

## 4. Reglas de comportamiento que debe respetar la app
- **Si se corta la red tras enviar un pago: NO reenvíes los tickets.** Consulta `/estado`: si está `PAGADO`, muestra el comprobante; si sigue `CREADO`, puedes reintentar el pago con los mismos tickets (es idempotente).
- Un nonce no se reutiliza jamás, ni al reintentar: arma un mensaje nuevo (nuevo nonce, nuevo timestamp, nueva firma).
- Un pedido vence a los 600 s; un ticket, a los 30 días. Muestra la vigencia y no uses tickets vencidos.
- No registres en logs llaves, tokens, tickets completos ni contraseñas. Contraseña: 8 a 128 caracteres; correo en minúsculas.
- Muestra mensajes amables por código de error. Códigos y su significado: sección 12 del contrato (`SALDO_INSUFICIENTE`, `TICKET_GASTADO`, `PEDIDO_EXPIRADO`, `DEMASIADOS_INTENTOS`, etc.).

## 5. Cómo comprobar que vas bien
- Los mismos pasos con `php artisan cliente:demo` (en `tienda/`) deben dar los mismos resultados que tu app.
- La bitácora (`php artisan bitacora:ver --n=30`) muestra qué recibió el servidor; si ves `FIRMA_INVALIDA` o `MENSAJE_CORRUPTO` al enviar un mensaje que crees correcto, el problema suele ser: cadena canónica distinta, OAEP con SHA-1, firma no DER o Base64 con saltos de línea.
- Para el equipo de seguridad (Entregable 4) la app debe poder mostrar o provocar: ticket modificado (E1), ticket reutilizado (E2), firma alterada (E3), mensaje cifrado modificado (E4) y credenciales inválidas (E5); coordina con Mau una pantalla/modo de pruebas.