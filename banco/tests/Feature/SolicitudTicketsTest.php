<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Cuenta;
use App\Models\Nonce;
use App\Models\Ticket;
use App\Services\Canonico;
use App\Services\CryptoService;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use stdClass;
use Tests\TestCase;

/**
 * Fase 6: POST /api/tickets/solicitar. Llaves temporales y reloj congelado en T0.
 * Cubre la emisión, E3 (firma alterada), la repetición del mensaje, el saldo y el formato.
 */
class SolicitudTicketsTest extends TestCase
{
    use RefreshDatabase;

    private const T0 = 1762500000;
    private const PW = 'ClaveSegura-1';

    private static ?array $llaves = null;
    private CryptoService $cliente;        // cifra sobres y genera nonces
    private CryptoService $firmante;       // firma como la app (llave privada del cliente)
    private string $id;                    // id_cuenta de Ana
    private string $token;                 // JWT de Ana

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::createFromTimestampUTC(self::T0));
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1, 'hashing.argon.threads' => 1]);

        self::$llaves ??= [
            'ec' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
            'rsa' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]),
            'cliente' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
            'otra' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
        ];
        $this->app->instance(CryptoService::class, new CryptoService(self::$llaves['ec'][0], self::$llaves['rsa'][0]));
        $this->app->instance(JwtService::class, new JwtService(str_repeat('s', 40), 900));
        $this->cliente = new CryptoService();
        $this->firmante = new CryptoService(self::$llaves['cliente'][0]);

        $this->id = $this->registrar('ana@ejemplo.com');
        $this->token = $this->iniciarSesion('ana@ejemplo.com');
    }

    // ---------------- Ayudas ----------------

    /** @return array{0:string,1:string} [privada PEM, pública PEM] */
    private function nuevaLlave(array $opciones): array
    {
        $r = openssl_pkey_new($opciones);
        openssl_pkey_export($r, $privada);

        return [$privada, openssl_pkey_get_details($r)['key']];
    }

    private function pubU(): string
    {
        return preg_replace('/-----[A-Z ]+-----|\s+/', '', self::$llaves['cliente'][1]);
    }

    /** Cifra y envía; devuelve [respuesta, K]. */
    private function enviar(string $ruta, array $cuerpo, ?string $token = null, bool $alterar = false): array
    {
        [$sobre, $k] = $this->cliente->crearSobre(json_encode($cuerpo), self::$llaves['rsa'][1]);
        if ($alterar) {
            $ct = base64_decode($sobre['ct'], true);
            $ct[0] = chr(ord($ct[0]) ^ 0x01);
            $sobre['ct'] = base64_encode($ct);
        }

        return [$this->postJson($ruta, $sobre, $token ? ['Authorization' => "Bearer $token"] : []), $k];
    }

    private function abrir(TestResponse $resp, string $k): array
    {
        return json_decode($this->cliente->abrirRespuesta($resp->json(), $k), true);
    }

    private function registrar(string $correo): string
    {
        [$r, $k] = $this->enviar('/api/registro', ['correo' => $correo, 'password' => self::PW, 'pub_u' => $this->pubU()]);
        $r->assertStatus(201);

        return $this->abrir($r, $k)['id_cuenta'];
    }

    private function iniciarSesion(string $correo): string
    {
        [$r, $k] = $this->enviar('/api/login', ['correo' => $correo, 'password' => self::PW]);
        $r->assertStatus(200);

        return $this->abrir($r, $k)['token'];
    }

    /** Arma una solicitud firmada como lo haría la app. Los $cambios se aplican DESPUÉS de firmar. */
    private function solicitud(array $den, array $cambios = [], ?CryptoService $firmante = null, ?string $nonce = null, ?int $ts = null, ?string $idCuenta = null): array
    {
        $idCuenta ??= $this->id;
        $nonce ??= $this->cliente->nonce();
        $ts ??= self::T0;
        $firma = ($firmante ?? $this->firmante)->firmar(Canonico::solicitud($idCuenta, $den, $nonce, $ts));

        return array_merge(['id_cuenta' => $idCuenta, 'denominaciones' => $den, 'nonce' => $nonce, 'timestamp' => $ts, 'firma_u' => $firma], $cambios);
    }

    private function solicitar(array $cuerpo, ?string $token = null): TestResponse
    {
        return $this->enviar('/api/tickets/solicitar', $cuerpo, $token ?? $this->token)[0];
    }

    private function saldo(): int
    {
        return Cuenta::find($this->id)->saldo;
    }

    // ---------------- Emisión correcta ----------------

    public function test_una_solicitud_valida_emite_los_tickets_y_descuenta_el_saldo(): void
    {
        [$r, $k] = $this->enviar('/api/tickets/solicitar', $this->solicitud([500 => 2, 300 => 1]), $this->token);

        $r->assertStatus(201)->assertJsonStructure(['iv', 'ct'])->assertJsonMissingPath('tickets');
        $d = $this->abrir($r, $k);
        $this->assertCount(3, $d['tickets']);
        $this->assertSame(8700, $d['saldo']);
        $this->assertSame(8700, $this->saldo());
        $this->assertSame(3, Ticket::where('id_cuenta', $this->id)->where('estado', 'ACTIVO')->whereNull('id_pedido')->count());
        $this->assertSame(2, Ticket::where('denominacion', 500)->count());
        $this->assertSame(1, Ticket::where('denominacion', 300)->count());
    }

    public function test_cada_ticket_trae_la_firma_del_banco_y_vigencia_de_30_dias(): void
    {
        [$r, $k] = $this->enviar('/api/tickets/solicitar', $this->solicitud([500 => 1, 100 => 2]), $this->token);
        $tickets = $this->abrir($r, $k)['tickets'];

        foreach ($tickets as $t) {
            $cadena = Canonico::ticket($t['id'], $t['denominacion'], $t['id_cuenta'], $t['emision'], $t['expiracion']);
            $this->assertTrue($this->cliente->verificar($cadena, $t['firma_b'], self::$llaves['ec'][1]), 'firma del ticket');
            $this->assertSame($this->id, $t['id_cuenta']);
            $this->assertSame(self::T0, $t['emision']);
            $this->assertSame(self::T0 + 2592000, $t['expiracion']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $t['id']);
            $this->assertFalse($this->cliente->verificar($cadena . 'x', $t['firma_b'], self::$llaves['ec'][1]));   // E1: alterado no verifica
        }
        $this->assertCount(3, array_unique(array_column($tickets, 'id')));
    }

    public function test_el_recibo_tiene_total_ids_ordenados_y_firma_del_banco(): void
    {
        [$r, $k] = $this->enviar('/api/tickets/solicitar', $this->solicitud([500 => 2, 300 => 1]), $this->token);
        $d = $this->abrir($r, $k);
        $rec = $d['recibo'];

        $esperados = array_column($d['tickets'], 'id');
        sort($esperados, SORT_STRING);
        $this->assertSame(1300, $rec['total']);
        $this->assertSame($esperados, $rec['ids']);
        $this->assertSame($this->id, $rec['id_cuenta']);
        $this->assertSame(self::T0, $rec['timestamp']);
        $this->assertTrue($this->cliente->verificar(Canonico::reciboAdq($this->id, 1300, $rec['ids'], $rec['timestamp']), $rec['firma_b'], self::$llaves['ec'][1]));
    }

    public function test_se_puede_gastar_exactamente_todo_el_saldo(): void
    {
        $this->solicitar($this->solicitud([500 => 20]))->assertStatus(201);
        $this->assertSame(0, $this->saldo());

        $this->solicitar($this->solicitud([20 => 1]))->assertStatus(422)->assertJsonPath('error.codigo', 'SALDO_INSUFICIENTE');
    }

    // ---------------- E3: firma alterada o inválida ----------------

    public function test_E3_una_firma_alterada_se_rechaza_sin_ningun_efecto(): void
    {
        $f = $this->solicitud([100 => 1]);
        $bytes = base64_decode($f['firma_u'], true);
        $bytes[10] = chr(ord($bytes[10]) ^ 0x01);
        $f['firma_u'] = base64_encode($bytes);

        $this->solicitar($f)->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');

        $this->assertSame(10000, $this->saldo());
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, Nonce::count(), 'el nonce no debe guardarse si la firma falló');
    }

    public function test_E3_si_se_modifican_las_denominaciones_despues_de_firmar_se_rechaza(): void
    {
        $f = $this->solicitud([100 => 1], ['denominaciones' => [500 => 5]]);   // firmó 100x1, envía 500x5

        $this->solicitar($f)->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
        $this->assertSame(10000, $this->saldo());
    }

    public function test_E3_una_firma_de_otra_llave_se_rechaza(): void
    {
        $intruso = new CryptoService(self::$llaves['otra'][0]);

        $this->solicitar($this->solicitud([100 => 1], [], $intruso))->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
        $this->assertSame(0, Ticket::count());
    }

    public function test_E3_una_firma_no_se_puede_reutilizar_con_otro_nonce(): void
    {
        $f = $this->solicitud([100 => 1]);
        $f['nonce'] = $this->cliente->nonce();

        $this->solicitar($f)->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
    }

    public function test_E3_la_firma_de_otro_tipo_de_mensaje_no_sirve(): void
    {
        $f = $this->solicitud([100 => 1]);
        // firma válida de la llave del cliente, pero sobre una cadena de otro tipo ("orden")
        $f['firma_u'] = $this->firmante->firmar('orden|v1|' . $this->id . '|100:1|' . $f['nonce'] . '|' . $f['timestamp']);

        $this->solicitar($f)->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
    }

    // ---------------- Repetición del mensaje ----------------

    public function test_repetir_el_mismo_mensaje_cifrado_no_emite_tickets_de_nuevo(): void
    {
        [$sobre] = $this->cliente->crearSobre(json_encode($this->solicitud([20 => 1])), self::$llaves['rsa'][1]);
        $cab = ['Authorization' => "Bearer {$this->token}"];

        $this->postJson('/api/tickets/solicitar', $sobre, $cab)->assertStatus(201);
        $this->postJson('/api/tickets/solicitar', $sobre, $cab)->assertStatus(409)->assertJsonPath('error.codigo', 'NONCE_REPETIDO');

        $this->assertSame(9980, $this->saldo());   // se descontó una sola vez
        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, Nonce::count());
    }

    // ---------------- Saldo y atomicidad ----------------

    public function test_saldo_insuficiente_no_emite_nada(): void
    {
        $this->solicitar($this->solicitud([500 => 21]))->assertStatus(422)->assertJsonPath('error.codigo', 'SALDO_INSUFICIENTE');

        $this->assertSame(10000, $this->saldo());
        $this->assertSame(0, Ticket::count());
    }

    public function test_si_falla_la_emision_a_la_mitad_el_saldo_no_cambia(): void
    {
        // Un CryptoService que falla al firmar el tercer ticket: simula una caída a mitad de la emisión.
        $falla = new class(self::$llaves['ec'][0], self::$llaves['rsa'][0]) extends CryptoService {
            public int $falloEn = 0;
            private int $n = 0;

            public function firmar(string $mensaje): string
            {
                if ($this->falloEn > 0 && ++$this->n === $this->falloEn) {
                    throw new \RuntimeException('fallo simulado');
                }

                return parent::firmar($mensaje);
            }
        };
        $this->app->instance(CryptoService::class, $falla);
        $falla->falloEn = 3;

        $this->solicitar($this->solicitud([20 => 3]))->assertStatus(500)->assertJsonPath('error.codigo', 'ERROR_INTERNO');

        $this->assertSame(10000, $this->saldo(), 'la transacción debe deshacer el descuento');
        $this->assertSame(0, Ticket::count());
    }

    // ---------------- Formato ----------------

    public function test_denominaciones_invalidas_se_rechazan_por_formato(): void
    {
        $malas = [
            'denominación no permitida' => [200 => 1],
            'cantidad cero' => [500 => 0],
            'cantidad negativa' => [500 => -1],
            'cantidad como texto' => [500 => '2'],
            'cantidad decimal' => [500 => 1.5],
            'más de 50 tickets' => [20 => 51],
            'vacío' => new stdClass(),
        ];
        foreach ($malas as $nombre => $den) {
            $f = $this->solicitud([500 => 1], ['denominaciones' => $den]);
            $this->solicitar($f)->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO', "caso: $nombre");
        }
        $this->assertSame(10000, $this->saldo());
        $this->assertSame(0, Ticket::count());
    }

    public function test_campos_invalidos_o_faltantes_se_rechazan_por_formato(): void
    {
        $this->solicitar($this->solicitud([500 => 1], ['id_cuenta' => 'abc']))->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');
        $this->solicitar($this->solicitud([500 => 1], ['firma_u' => 'esto no es base64!!']))->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');

        $sinFirma = $this->solicitud([500 => 1]);
        unset($sinFirma['firma_u']);
        $this->solicitar($sinFirma)->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');
    }

    // ---------------- Sesión, tiempo y certificado ----------------

    public function test_sin_token_da_TOKEN_INVALIDO(): void
    {
        [$r] = $this->enviar('/api/tickets/solicitar', $this->solicitud([20 => 1]));

        $r->assertStatus(401)->assertJsonPath('error.codigo', 'TOKEN_INVALIDO');
    }

    public function test_el_token_de_una_cuenta_no_sirve_para_pedir_tickets_de_otra(): void
    {
        $idBeto = $this->registrar('beto@ejemplo.com');

        $this->solicitar($this->solicitud([20 => 1], [], null, null, null, $idBeto))   // token de Ana, id_cuenta de Beto
            ->assertStatus(401)->assertJsonPath('error.codigo', 'TOKEN_INVALIDO');
        $this->assertSame(0, Ticket::count());
    }

    public function test_timestamp_fuera_de_rango_se_rechaza(): void
    {
        $this->solicitar($this->solicitud([20 => 1], [], null, null, self::T0 - 121))
            ->assertStatus(400)->assertJsonPath('error.codigo', 'TIMESTAMP_FUERA_DE_RANGO');
    }

    public function test_un_cert_u_vencido_se_rechaza(): void
    {
        Cuenta::query()->update(['cert_expiracion' => self::T0]);

        $this->solicitar($this->solicitud([20 => 1]))->assertStatus(403)->assertJsonPath('error.codigo', 'CERTIFICADO_INVALIDO');
        $this->assertSame(0, Ticket::count());
    }

    public function test_un_sobre_alterado_se_rechaza(): void
    {
        [$r] = $this->enviar('/api/tickets/solicitar', $this->solicitud([20 => 1]), $this->token, true);

        $r->assertStatus(400)->assertJsonPath('error.codigo', 'MENSAJE_CORRUPTO');
    }

    // ---------------- Bitácora ----------------

    public function test_la_bitacora_registra_la_emision_y_los_rechazos_sin_firmas_ni_tokens(): void
    {
        $this->solicitar($this->solicitud([500 => 2, 300 => 1]))->assertStatus(201);
        $this->solicitar($this->solicitud([100 => 1], ['firma_u' => $this->firmante->firmar('otra cosa')]))->assertStatus(403);
        $this->solicitar($this->solicitud([500 => 21]))->assertStatus(422);

        $texto = json_encode(Bitacora::all()->toArray());
        $this->assertStringContainsString('Emitidos 3 tickets por $1300', $texto);
        $this->assertStringContainsString('FIRMA_INVALIDA', $texto);
        $this->assertStringContainsString('SALDO_INSUFICIENTE', $texto);
        $this->assertStringNotContainsString($this->token, $texto);
        $this->assertStringNotContainsString('otra cosa', $texto);
    }
}
