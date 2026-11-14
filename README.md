# Proyecto Integrador — Billetera con tickets (Banco + Tienda + App)

Intercomunicación y Seguridad en Redes. Una app Android (billetera) compra **tickets** ($500, $300, $100, $50, $20) a un servidor **Banco** y con ellos paga a un servidor **Tienda**, usando cifrado simétrico, asimétrico, hash y firmas digitales.

| Parte | Carpeta | Estado | Responsable |
|---|---|---|---|
| Servidor Banco (Laravel, puerto 8001) | `banco/` | Terminado y probado | Mau |
| Servidor Tienda (Laravel, puerto 8002) | `tienda/` | Terminado y probado | Mau |
| Paneles web (uno por servidor, solo lectura) | dentro de `banco/` y `tienda/` | Pendiente | Compañero de paneles → `docs/GUIA-PANELES-WEB.md` |
| App Android (Kotlin) | fuera de este repo o `app/` | Pendiente | Compañero de la app → `docs/GUIA-APP-ANDROID.md` |

## Qué leer primero
1. **`docs/contrato-integracion.md`** (v1.5): es la ley. Define endpoints, formatos, firmas, errores y orden de verificación. Si algo no coincide con el código, manda el contrato; avisa a Mau.
2. `docs/banco-base-de-datos.md` y `docs/tienda-base-de-datos.md`: tablas y decisiones.
3. `docs/diagrama-pago-canje.png`: cómo viaja un pago.

## Estructura del repositorio
```
claves-publicas/    4 llaves PÚBLICAS (sí se suben a Git)
banco/              Laravel: cuentas, tickets, canje
  app/Services/     CryptoService, Canonico, ProcesadorSobre, CanjeadorTickets...
  app/Http/Controllers/
  app/Console/Commands/   cliente:demo, banco:resumen, bitacora:ver
  storage/app/keys/ llaves PRIVADAS (NO se suben a Git)
tienda/             Laravel: catálogo, pedidos, pago
  app/Services/     ProcesadorPagos, BancoCliente, CertificadoUsuario...
  app/Console/Commands/   cliente:demo, tienda:resumen, bitacora:ver
docs/               contrato y documentación
```

## Instalación (todos los que van a correr los servidores)
Sistema recomendado: **Ubuntu en WSL2** (Windows) o Linux/macOS. Usa **la misma versión de PHP que Mau** (`php -v`).

```bash
sudo apt update
sudo apt install -y git unzip curl php-cli php-sqlite3 php-curl php-mbstring php-xml php-zip php-bcmath
# Composer: https://getcomposer.org/download/  (verifica con: composer --version)
git clone <URL-DEL-REPO>
cd <carpeta-del-repo>
```
Comprueba: `php -m | grep -i -E "openssl|sqlite|curl|mbstring"` debe listar las cuatro.

### Preparar cada servidor (repite en `banco/` y en `tienda/`)
```bash
cd banco            # luego repite con: cd ../tienda
composer install
cp .env.example .env
php artisan key:generate
```
- **Solo en `banco/.env`**: agrega `JWT_SECRET=` con un valor de **32 o más caracteres**. Genera uno con `php -r "echo bin2hex(random_bytes(32));"`.
- **Solo en `tienda/.env`**: `BANCO_URL=http://localhost:8001` (ya es el valor por omisión).
- La base es SQLite: `touch database/database.sqlite` si no existe, y verifica que `.env` tenga `DB_CONNECTION=sqlite`.

```bash
php artisan migrate          # banco
php artisan migrate --seed   # tienda (carga el catálogo de 12 productos)
```

### Llaves privadas (IMPORTANTE)
Los servidores firman y descifran con llaves **privadas** que NO están en Git (solo las públicas, en `claves-publicas/`). Las públicas del repo corresponden a las privadas de Mau, así que:
- **Pide a Mau** (por mensaje directo, nunca por el repo) las carpetas `banco/storage/app/keys/` y `tienda/storage/app/keys/` y colócalas en el mismo lugar. Cada una contiene `rsa_priv.pem` y `ec_priv.pem`. Así tu servidor local es compatible con las llaves que ya usa la app.
- Si en cambio **regeneras** las llaves, se rompe la compatibilidad con la app: hay que reemplazar las 4 públicas en `claves-publicas/` y en la app (`res/raw`), y avisar a todos. Solo hazlo de acuerdo con Mau. Comandos (ejemplo del banco, desde `banco/storage/app/keys/`):
```bash
openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out ec_priv.pem
openssl pkey -in ec_priv.pem -pubout -out ../../../../claves-publicas/banco_ec_pub.pem
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out rsa_priv.pem
openssl pkey -in rsa_priv.pem -pubout -out ../../../../claves-publicas/banco_rsa_pub.pem
```
(en la tienda: `tienda_ec_pub.pem` y `tienda_rsa_pub.pem`).

## Levantar y probar
Dos terminales, una por servidor:
```bash
cd banco  && php artisan serve --port=8001
cd tienda && php artisan serve --port=8002
```
Para que un teléfono físico o la máquina de un compañero los alcance: `php artisan serve --host=0.0.0.0 --port=8001` (y 8002) y usar la IP de la computadora. Desde el emulador de Android: `http://10.0.2.2:8001` y `http://10.0.2.2:8002`.

**Pruebas automáticas** (se ejecutan DENTRO de cada carpeta, no desde la raíz):
```bash
cd banco  && php artisan test    # 146 pruebas, todas en verde
cd tienda && php artisan test    # 118 pruebas, todas en verde
```
**Demostración de extremo a extremo** (con los dos servidores encendidos), desde `tienda/`:
```bash
php artisan cliente:demo         # 24 bloques; todos deben salir [OK]
```
Este comando es una **app simulada en PHP** que hace todo el flujo real (registro, compra de tickets, pedido, pago, ataques E1 a E4). Su código (`tienda/app/Console/Commands/ClienteDemo.php` y `banco/.../ClienteDemo.php`) es la mejor referencia para implementar el cliente.

Inspección de datos:
```bash
php artisan banco:resumen        # en banco/: cuentas, saldos, tickets
php artisan tienda:resumen       # en tienda/: pedidos y estados
php artisan bitacora:ver --n=30  # en cualquiera: últimos eventos aceptados/rechazados
```
Para reiniciar datos de prueba: `php artisan migrate:fresh` (banco) y `php artisan migrate:fresh --seed` (tienda). Borra TODO lo guardado.

## Endpoints (resumen; el detalle está en el contrato)
**Banco (8001):** `POST /api/registro`, `/api/login`, `/api/cuenta/consulta`, `/api/tickets/solicitar`; para la tienda: `/api/canje`, `/api/canjes/consulta`.
**Tienda (8002):** `GET /api/catalogo`, `POST /api/pedidos`, `/api/pedidos/{id}/pago`, `/api/pedidos/{id}/estado`.
Todos los `POST` van en un **sobre cifrado** y las respuestas de éxito vuelven cifradas; los errores vuelven **en claro** como `{"error":{"codigo":"...","mensaje":"..."}}`.

## Reglas del equipo
- **Nunca** subas a Git: `.env`, `storage/app/keys/`, `*.pem` privados, `database.sqlite`. Antes de cada commit: `git status` y confirma que no aparecen. (`.gitignore` ya los excluye; si ves uno, no hagas commit y avisa.)
- No cambies `Services/`, `Canonico.php`, `CryptoService.php` ni los endpoints sin avisar a Mau: la app depende de formatos exactos.
- Un tema por rama y mensajes de commit claros. Haz `git pull` antes de empezar a trabajar.
- Quien necesite un cambio en el contrato lo propone a Mau; se actualiza la versión (tabla «Historial de cambios») y se avisa a todos.

## Problemas frecuentes
| Síntoma | Causa y solución |
|---|---|
| `could not find driver` | Falta `php-sqlite3`: `sudo apt install php-sqlite3` |
| `Falta la llave pública del banco/tienda` | Falta `claves-publicas/` o el archivo; haz `git pull` |
| Errores al descifrar / `MENSAJE_CORRUPTO` siempre | Llaves privadas que no corresponden a las públicas del repo: pide a Mau sus `storage/app/keys/` |
| `TIMESTAMP_FUERA_DE_RANGO` | El reloj de tu PC (o del emulador) difiere más de 120 s. En WSL: `sudo hwclock -s`; en el emulador, activa la hora automática |
| `Address already in use` | El puerto está ocupado: cierra el `serve` anterior o cambia el puerto |
| `ERROR_INTERNO` al pagar | La tienda no alcanza al banco: ¿está encendido y `BANCO_URL` correcto? El detalle está en `storage/logs/laravel.log` |
| `php artisan test` no encuentra nada | Ejecútalo dentro de `banco/` o `tienda/`, no en la raíz |