<?php

namespace Tests;

use App\Services\BancoCliente;
use App\Services\Canonico;
use App\Services\CertificadoUsuario;
use App\Services\CryptoService;
use Database\Seeders\ProductoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Base de las pruebas de la Tienda (Fase 7). Usa llaves temporales (no las de storage/app/keys) y reloj congelado en T0.
 * Actores: el banco (firma Cert_U), la tienda, el cliente A, el cliente B y un atacante.
 */
abstract class TiendaTestCase extends TestCase
{
    use RefreshDatabase;

    protected const T0 = 1762500000;
    protected const UN_ANIO = 31536000;

    private static ?array $llaves = null;
    protected CryptoService $cliente;      // cifra sobres y genera nonces (no necesita llaves privadas)
    protected CryptoService $banco;        // firma los Cert_U como lo haría el banco
    protected CryptoService $firmanteA;    // firma como la app de A
    protected CryptoService $firmanteB;
    protected CryptoService $atacante;
    protected BancoFalso $bancoFalso;      // el banco que ve la tienda al pagar (Fase 8)
    protected string $idA;
    protected string $idB;
    protected array $certA;
    protected array $certB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::createFromTimestampUTC(self::T0));

        self::$llaves ??= [
            'tienda_ec' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
            'tienda_rsa' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]),
            'banco_ec' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
            'a' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
            'b' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
            'atacante' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
            'rsa_cliente' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]),
        ];
        $this->app->instance(CryptoService::class, new CryptoService(self::$llaves['tienda_ec'][0], self::$llaves['tienda_rsa'][0]));
        $this->app->instance(CertificadoUsuario::class, new CertificadoUsuario(new CryptoService(), self::$llaves['banco_ec'][1]));

        $this->cliente = new CryptoService();
        $this->banco = new CryptoService(self::$llaves['banco_ec'][0]);
        $this->firmanteA = new CryptoService(self::$llaves['a'][0]);
        $this->firmanteB = new CryptoService(self::$llaves['b'][0]);
        $this->atacante = new CryptoService(self::$llaves['atacante'][0]);

        $this->bancoFalso = new BancoFalso($this->banco);
        $this->app->instance(BancoCliente::class, $this->bancoFalso);

        $this->seed(ProductoSeeder::class);
        $this->idA = (string) Str::uuid();
        $this->idB = (string) Str::uuid();
        $this->certA = $this->certificado($this->idA, $this->pubBase64('a'));
        $this->certB = $this->certificado($this->idB, $this->pubBase64('b'));
    }

    // ---------------- Llaves y certificados ----------------

    /** @return array{0:string,1:string} [privada PEM, pública PEM] */
    protected function nuevaLlave(array $opciones): array
    {
        $r = openssl_pkey_new($opciones);
        openssl_pkey_export($r, $privada);

        return [$privada, openssl_pkey_get_details($r)['key']];
    }

    protected function pem(string $nombre): string
    {
        return self::$llaves[$nombre][1];
    }

    /** Llave pública en Base64 X.509 (lo que envía la app en el registro y va dentro del Cert_U). */
    protected function pubBase64(string $nombre): string
    {
        return preg_replace('/-----[A-Z ]+-----|\s+/', '', self::$llaves[$nombre][1]);
    }

    /** Cert_U firmado por el banco. Se puede pedir vencido ($expiracion) o firmado por otro ($firmante). */
    protected function certificado(string $idCuenta, string $pubU, ?int $expiracion = null, ?CryptoService $firmante = null): array
    {
        $expiracion ??= self::T0 + self::UN_ANIO;
        $firma = ($firmante ?? $this->banco)->firmar(Canonico::cert($idCuenta, $pubU, $expiracion));

        return ['id_cuenta' => $idCuenta, 'pub_u' => $pubU, 'expiracion' => $expiracion, 'firma_b' => $firma];
    }

    // ---------------- Envío ----------------

    /** Cifra con la RSA de la tienda y envía; devuelve [respuesta, K]. */
    protected function enviar(string $ruta, array $cuerpo, bool $alterar = false): array
    {
        [$sobre, $k] = $this->cliente->crearSobre(json_encode($cuerpo), self::$llaves['tienda_rsa'][1]);
        if ($alterar) {
            $ct = base64_decode($sobre['ct'], true);
            $ct[0] = chr(ord($ct[0]) ^ 0x01);   // cambia un solo bit del cifrado
            $sobre['ct'] = base64_encode($ct);
        }

        return [$this->postJson($ruta, $sobre), $k];
    }

    protected function abrir(TestResponse $resp, string $k): array
    {
        return json_decode($this->cliente->abrirRespuesta($resp->json(), $k), true);
    }

    protected function ahora(): int
    {
        return now()->timestamp;
    }

    // ---------------- Mensajes firmados ----------------

    /** @return array<int,array{id:int,cantidad:int}> Taza x1 (370) y Libreta x2 (120): total 490 */
    protected function itemsBasicos(): array
    {
        return [['id' => 1, 'cantidad' => 1], ['id' => 3, 'cantidad' => 2]];
    }

    /** Petición de pedido firmada como la app. Los $cambios se aplican DESPUÉS de firmar. */
    protected function pedido(array $items, array $cambios = [], ?array $cert = null, ?CryptoService $firmante = null, ?string $nonce = null, ?int $ts = null): array
    {
        $cert ??= $this->certA;
        $nonce ??= $this->cliente->nonce();
        $ts ??= $this->ahora();
        $firma = ($firmante ?? $this->firmanteA)->firmar(Canonico::crearPedido($cert['id_cuenta'], $items, $nonce, $ts));

        return array_merge(['cert_u' => $cert, 'items' => $items, 'nonce' => $nonce, 'timestamp' => $ts, 'firma_u' => $firma], $cambios);
    }

    protected function crearPedido(array $cuerpo): TestResponse
    {
        return $this->enviar('/api/pedidos', $cuerpo)[0];
    }

    /** Crea un pedido válido de A y devuelve su id_pedido. */
    protected function pedidoDeA(): string
    {
        [$r, $k] = $this->enviar('/api/pedidos', $this->pedido($this->itemsBasicos()));
        $r->assertStatus(201);

        return $this->abrir($r, $k)['pedido']['id_pedido'];
    }

    /** Consulta de estado firmada como la app. */
    protected function consulta(string $idPedido, array $cambios = [], ?array $cert = null, ?CryptoService $firmante = null, ?string $nonce = null, ?int $ts = null): array
    {
        $cert ??= $this->certA;
        $nonce ??= $this->cliente->nonce();
        $ts ??= $this->ahora();
        $firma = ($firmante ?? $this->firmanteA)->firmar(Canonico::consultaPedido($idPedido, $cert['id_cuenta'], $nonce, $ts));

        return array_merge(['cert_u' => $cert, 'nonce' => $nonce, 'timestamp' => $ts, 'firma_u' => $firma], $cambios);
    }

    // ---------------- Pagos (Fase 8) ----------------

    /**
     * Ticket firmado por el banco. Se puede pedir de otra cuenta, con otra firma, ya vencido, etc.
     * @return array{id:string,denominacion:int,id_cuenta:string,emision:int,expiracion:int,firma_b:string}
     */
    protected function ticket(int $denominacion, ?string $idCuenta = null, ?CryptoService $firmante = null, ?int $emision = null, ?int $expiracion = null): array
    {
        $id = (string) Str::uuid();
        $idCuenta ??= $this->idA;
        $emision ??= self::T0;
        $expiracion ??= $emision + 2592000;

        return [
            'id' => $id, 'denominacion' => $denominacion, 'id_cuenta' => $idCuenta, 'emision' => $emision, 'expiracion' => $expiracion,
            'firma_b' => ($firmante ?? $this->banco)->firmar(Canonico::ticket($id, $denominacion, $idCuenta, $emision, $expiracion)),
        ];
    }

    /** @return array<int,array> varios tickets de la cuenta A (por omisión): 300+100+50+20+20 = 490, el total de pedidoDeA() */
    protected function ticketsDe(array $denominaciones = [300, 100, 50, 20, 20], ?string $idCuenta = null): array
    {
        return array_map(fn (int $d) => $this->ticket($d, $idCuenta), $denominaciones);
    }

    /**
     * Pago firmado como la app. Opciones: cert, firmante, monto, nonce, ts. Los $cambios (de la raíz) se aplican DESPUÉS de firmar.
     */
    protected function pago(string $idPedido, array $tickets, array $op = [], array $cambios = []): array
    {
        $cert = $op['cert'] ?? $this->certA;
        $monto = $op['monto'] ?? array_sum(array_column($tickets, 'denominacion'));
        $nonce = $op['nonce'] ?? $this->cliente->nonce();
        $ts = $op['ts'] ?? $this->ahora();
        $firma = ($op['firmante'] ?? $this->firmanteA)->firmar(Canonico::orden($idPedido, $monto, array_column($tickets, 'id'), $nonce, $ts));
        $orden = ['id_pedido' => $idPedido, 'monto' => $monto, 'tickets' => $tickets, 'nonce' => $nonce, 'timestamp' => $ts];

        return array_merge(['cert_u' => $cert, 'orden' => $orden, 'firma_u' => $firma], $cambios);
    }

    protected function pagar(string $idPedido, array $cuerpo, bool $alterar = false): TestResponse
    {
        return $this->enviar("/api/pedidos/$idPedido/pago", $cuerpo, $alterar)[0];
    }

    /** Paga el pedido de A con sus tickets y devuelve la respuesta ya descifrada. */
    protected function pagarOk(string $idPedido, ?array $tickets = null): array
    {
        $tickets ??= $this->ticketsDe();
        [$r, $k] = $this->enviar("/api/pedidos/$idPedido/pago", $this->pago($idPedido, $tickets));
        $r->assertStatus(200);

        return $this->abrir($r, $k);
    }

    /** Consulta el estado del pedido de A y devuelve la respuesta ya descifrada. */
    protected function estadoDe(string $idPedido): array
    {
        [$r, $k] = $this->enviar("/api/pedidos/$idPedido/estado", $this->consulta($idPedido));
        $r->assertStatus(200);

        return $this->abrir($r, $k);
    }

    /** Si se espera que no haya pasado nada: ni pedidos, ni renglones, ni nonces guardados. */
    protected function assertSinEfectos(): void
    {
        $this->assertSame(0, \App\Models\Pedido::count());
        $this->assertSame(0, \App\Models\PedidoItem::count());
        $this->assertSame(0, \App\Models\Nonce::count());
    }
}
