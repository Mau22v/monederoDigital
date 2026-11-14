<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Canje;
use App\Models\Cuenta;
use App\Models\Nonce;
use App\Models\Ticket;
use App\Services\CanjeadorTickets;
use App\Services\Canonico;
use App\Services\CryptoService;
use App\Services\FirmaTienda;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Fase 8 (lado Banco): POST /api/canje y POST /api/canjes/consulta.
 * Cubre el canje, E1 (ticket que no es del banco), E2 (ticket ya gastado), E3 (firmas alteradas),
 * la idempotencia, el orden de verificación, la atomicidad y la bitácora. Llaves temporales y reloj en T0.
 */
class CanjeTest extends TestCase
{
    use RefreshDatabase;

    private const T0 = 1762500000;
    private const PW = 'ClaveSegura-1';
    private const P1 = '11111111-1111-4111-8111-111111111111';
    private const P2 = '22222222-2222-4222-8222-222222222222';

    private static ?array $llaves = null;
    private CryptoService $cliente;     // cifra sobres y genera nonces
    private CryptoService $firmanteA;   // firma como la app de Ana
    private CryptoService $firmanteB;   // firma como la app de Beto
    private CryptoService $tienda;      // firma como la tienda
    private string $idA;
    private string $idB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::createFromTimestampUTC(self::T0));
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1, 'hashing.argon.threads' => 1]);

        self::$llaves ??= [
            'ec' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
            'rsa' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]),
            'ana' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
            'beto' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
            'tienda' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
            'intruso' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
        ];
        $banco = new CryptoService(self::$llaves['ec'][0], self::$llaves['rsa'][0]);
        $this->app->instance(CryptoService::class, $banco);
        $this->app->instance(JwtService::class, new JwtService(str_repeat('s', 40), 900));
        $this->app->instance(FirmaTienda::class, new FirmaTienda($banco, self::$llaves['tienda'][1]));
        $this->cliente = new CryptoService();
        $this->firmanteA = new CryptoService(self::$llaves['ana'][0]);
        $this->firmanteB = new CryptoService(self::$llaves['beto'][0]);
        $this->tienda = new CryptoService(self::$llaves['tienda'][0]);

        $this->idA = $this->registrar('ana@ejemplo.com', 'ana');
        $this->idB = $this->registrar('beto@ejemplo.com', 'beto');
    }

    // ---------------- Ayudas ----------------

    /** @return array{0:string,1:string} [privada PEM, pública PEM] */
    private function nuevaLlave(array $opciones): array
    {
        $r = openssl_pkey_new($opciones);
        openssl_pkey_export($r, $privada);

        return [$privada, openssl_pkey_get_details($r)['key']];
    }

    private function pubU(string $quien): string
    {
        return preg_replace('/-----[A-Z ]+-----|\s+/', '', self::$llaves[$quien][1]);
    }

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

    private function registrar(string $correo, string $quien): string
    {
        [$r, $k] = $this->enviar('/api/registro', ['correo' => $correo, 'password' => self::PW, 'pub_u' => $this->pubU($quien)]);
        $r->assertStatus(201);

        return $this->abrir($r, $k)['id_cuenta'];
    }

    private function token(string $correo): string
    {
        [$r, $k] = $this->enviar('/api/login', ['correo' => $correo, 'password' => self::PW]);

        return $this->abrir($r, $k)['token'];
    }

    /** La cuenta compra tickets (como la app) y devuelve la lista de tickets. */
    private function comprar(string $quien, array $den): array
    {
        $id = $quien === 'ana' ? $this->idA : $this->idB;
        $firmante = $quien === 'ana' ? $this->firmanteA : $this->firmanteB;
        $nonce = $this->cliente->nonce();
        $cuerpo = [
            'id_cuenta' => $id, 'denominaciones' => $den, 'nonce' => $nonce, 'timestamp' => now()->timestamp,
            'firma_u' => $firmante->firmar(Canonico::solicitud($id, $den, $nonce, now()->timestamp)),
        ];
        [$r, $k] = $this->enviar('/api/tickets/solicitar', $cuerpo, $this->token($quien . '@ejemplo.com'));
        $r->assertStatus(201);

        return $this->abrir($r, $k)['tickets'];
    }

    /** @return string[] */
    private function ids(array $tickets): array
    {
        $ids = array_column($tickets, 'id');
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * Arma el mensaje de canje como lo hace la tienda. Los $cambios se aplican DESPUÉS de firmar.
     * Opciones: firmaU (CryptoService del cliente), firmaT (CryptoService de la tienda), nonce, ts.
     */
    private function canje(string $idPedido, int $monto, array $ids, array $cambios = [], array $op = []): array
    {
        $ids2 = $ids;
        sort($ids2, SORT_STRING);
        $nonceT = $op['nonce'] ?? $this->cliente->nonce();
        $tsT = $op['ts'] ?? now()->timestamp;
        $nonceU = $this->cliente->nonce();
        $tsU = now()->timestamp - 5;   // el timestamp de la orden NO se compara con el reloj del banco
        $firmaU = ($op['firmaU'] ?? $this->firmanteA)->firmar(Canonico::orden($idPedido, $monto, $ids2, $nonceU, $tsU));
        $firmaT = ($op['firmaT'] ?? $this->tienda)->firmar(Canonico::canje($idPedido, $monto, $ids2, $nonceT, $tsT));

        return array_merge([
            'id_pedido' => $idPedido, 'monto' => $monto, 'ids' => $ids,
            'orden' => ['nonce' => $nonceU, 'timestamp' => $tsU, 'firma_u' => $firmaU],
            'nonce' => $nonceT, 'timestamp' => $tsT, 'firma_t' => $firmaT,
        ], $cambios);
    }

    private function pedirCanje(array $cuerpo): TestResponse
    {
        return $this->enviar('/api/canje', $cuerpo)[0];   // sin JWT: lo invoca la tienda
    }

    private function consulta(string $idPedido, array $cambios = [], ?CryptoService $firmante = null): TestResponse
    {
        $nonce = $this->cliente->nonce();
        $ts = now()->timestamp;
        $cuerpo = array_merge([
            'id_pedido' => $idPedido, 'nonce' => $nonce, 'timestamp' => $ts,
            'firma_t' => ($firmante ?? $this->tienda)->firmar(Canonico::consultaCanje($idPedido, $nonce, $ts)),
        ], $cambios);

        return $this->enviar('/api/canjes/consulta', $cuerpo)[0];
    }

    private function saldo(string $id): int
    {
        return Cuenta::find($id)->saldo;
    }

    private function assertSinEfectos(): void
    {
        $this->assertSame(0, Canje::count(), 'no debe existir ningún canje');
        $this->assertSame(0, Ticket::where('estado', 'GASTADO')->count(), 'ningún ticket debe quedar GASTADO');
        $this->assertSame(0, Ticket::whereNotNull('id_pedido')->count());
    }

    // ---------------- Canje correcto ----------------

    public function test_un_canje_valido_gasta_los_tickets_y_devuelve_el_recibo_firmado(): void
    {
        $t = $this->comprar('ana', [500 => 1, 300 => 1]);
        $ids = $this->ids($t);
        $saldoAntes = $this->saldo($this->idA);

        [$r, $k] = $this->enviar('/api/canje', $this->canje(self::P1, 800, $ids));

        $r->assertStatus(200)->assertJsonStructure(['iv', 'ct'])->assertJsonMissingPath('recibo');
        $rec = $this->abrir($r, $k)['recibo'];
        $this->assertSame(self::P1, $rec['id_pedido']);
        $this->assertSame(800, $rec['monto']);
        $this->assertSame($ids, $rec['ids']);
        $this->assertSame(self::T0, $rec['timestamp']);
        $this->assertTrue($this->cliente->verificar(Canonico::reciboCanje(self::P1, 800, $ids, self::T0), $rec['firma_b'], self::$llaves['ec'][1]));
        $this->assertFalse($this->cliente->verificar(Canonico::reciboCanje(self::P1, 700, $ids, self::T0), $rec['firma_b'], self::$llaves['ec'][1]));

        $this->assertSame(2, Ticket::where('estado', 'GASTADO')->where('id_pedido', self::P1)->count());
        $canje = Canje::find(self::P1);
        $this->assertSame($this->idA, $canje->id_cuenta);
        $this->assertSame(800, (int) $canje->monto);
        $this->assertSame($rec['firma_b'], $canje->firma_b);
        $this->assertSame($saldoAntes, $this->saldo($this->idA), 'canjear no mueve el saldo: el dinero ya salió al comprar los tickets');
    }

    public function test_el_canje_no_requiere_jwt_y_acepta_los_ids_en_cualquier_orden(): void
    {
        $ids = $this->ids($this->comprar('ana', [100 => 2, 20 => 1]));
        $al_reves = array_reverse($ids);

        $this->enviar('/api/canje', $this->canje(self::P1, 220, $al_reves))[0]->assertStatus(200);
    }

    public function test_solo_se_gastan_los_tickets_indicados(): void
    {
        $t = $this->comprar('ana', [100 => 3]);
        $ids = $this->ids($t);

        $this->pedirCanje($this->canje(self::P1, 200, array_slice($ids, 0, 2)))->assertStatus(200);

        $this->assertSame(2, Ticket::where('estado', 'GASTADO')->count());
        $this->assertSame(1, Ticket::where('estado', 'ACTIVO')->whereNull('id_pedido')->count());
    }

    // ---------------- Idempotencia y duplicados ----------------

    public function test_repetir_el_canje_del_mismo_pedido_devuelve_el_mismo_recibo(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));

        [$r1, $k1] = $this->enviar('/api/canje', $this->canje(self::P1, 500, $ids));
        [$r2, $k2] = $this->enviar('/api/canje', $this->canje(self::P1, 500, $ids));   // mensaje nuevo (otro nonce)

        $r1->assertStatus(200);
        $r2->assertStatus(200);
        $this->assertSame($this->abrir($r1, $k1)['recibo'], $this->abrir($r2, $k2)['recibo']);
        $this->assertSame(1, Canje::count());
        $this->assertSame(1, Ticket::where('estado', 'GASTADO')->count());
    }

    public function test_el_mismo_pedido_con_otros_tickets_es_pedido_duplicado(): void
    {
        $a = $this->ids($this->comprar('ana', [500 => 1]));
        $b = $this->ids($this->comprar('ana', [500 => 1]));
        $this->pedirCanje($this->canje(self::P1, 500, $a))->assertStatus(200);

        $this->pedirCanje($this->canje(self::P1, 500, $b))->assertStatus(409)->assertJsonPath('error.codigo', 'PEDIDO_DUPLICADO');

        $this->assertSame(1, Ticket::where('id', $b[0])->where('estado', 'ACTIVO')->count(), 'los tickets del segundo intento siguen ACTIVOS');
        $this->assertSame(1, Canje::count());
    }

    public function test_el_mismo_pedido_con_otro_monto_es_pedido_duplicado(): void
    {
        $a = $this->ids($this->comprar('ana', [500 => 1]));
        $this->pedirCanje($this->canje(self::P1, 500, $a))->assertStatus(200);

        $this->pedirCanje($this->canje(self::P1, 300, $a))->assertStatus(409)->assertJsonPath('error.codigo', 'PEDIDO_DUPLICADO');
    }

    // ---------------- E2: ticket ya gastado ----------------

    public function test_E2_un_ticket_ya_gastado_no_se_puede_usar_en_otro_pedido(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));
        $this->pedirCanje($this->canje(self::P1, 500, $ids))->assertStatus(200);

        $this->pedirCanje($this->canje(self::P2, 500, $ids))->assertStatus(409)->assertJsonPath('error.codigo', 'TICKET_GASTADO');

        $this->assertSame(1, Canje::count());
        $this->assertNull(Canje::find(self::P2));
        $this->assertSame(self::P1, Ticket::find($ids[0])->id_pedido, 'el ticket sigue ligado al primer pedido');
    }

    public function test_E2_el_ticket_gastado_se_informa_antes_que_un_monto_incorrecto(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));
        $this->pedirCanje($this->canje(self::P1, 500, $ids))->assertStatus(200);

        // Orden del contrato (sección 13, paso 8): primero el estado de los tickets, después la suma.
        $this->pedirCanje($this->canje(self::P2, 20, $ids))->assertStatus(409)->assertJsonPath('error.codigo', 'TICKET_GASTADO');
    }

    public function test_E2_si_un_solo_ticket_esta_gastado_no_se_gasta_ninguno(): void
    {
        $t = $this->ids($this->comprar('ana', [100 => 3]));
        $this->pedirCanje($this->canje(self::P1, 100, [$t[0]]))->assertStatus(200);

        $this->pedirCanje($this->canje(self::P2, 300, $t))->assertStatus(409)->assertJsonPath('error.codigo', 'TICKET_GASTADO');

        $this->assertSame(2, Ticket::where('estado', 'ACTIVO')->count(), 'los otros dos siguen ACTIVOS: todo o nada');
        $this->assertSame(1, Canje::count());
    }

    public function test_E2_la_carrera_perdida_por_un_ticket_deshace_todo_el_canje(): void
    {
        $t = $this->ids($this->comprar('ana', [100 => 3]));
        // Simula que otra petición gasta el ticket 3 justo DESPUÉS de las comprobaciones y ANTES del cambio de estado.
        $this->app->instance(CanjeadorTickets::class, new class($this->app->make(CryptoService::class), $t[2]) extends CanjeadorTickets {
            public function __construct(CryptoService $c, private string $perdido)
            {
                parent::__construct($c);
            }

            protected function gastar(array $ids, string $idPedido, string $idCuenta, int $ahora): bool
            {
                Ticket::where('id', $this->perdido)->update(['estado' => Ticket::GASTADO]);

                return parent::gastar($ids, $idPedido, $idCuenta, $ahora);
            }
        });

        $this->pedirCanje($this->canje(self::P1, 300, $t))->assertStatus(409)->assertJsonPath('error.codigo', 'TICKET_GASTADO');

        $this->assertNull(Canje::find(self::P1), 'el canje se deshizo');
        $this->assertSame(0, Ticket::whereNotNull('id_pedido')->count(), 'ningún ticket quedó ligado al pedido');
        $this->assertSame('ACTIVO', Ticket::find($t[0])->estado, 'los tickets que sí estaban libres siguen ACTIVOS');
        $this->assertSame('ACTIVO', Ticket::find($t[1])->estado);
    }

    // ---------------- Vigencia, monto y propiedad ----------------

    public function test_un_ticket_expirado_se_rechaza(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));
        $this->travelTo(Carbon::createFromTimestampUTC(self::T0 + 2592000));   // justo en la expiración (30 días)

        $this->pedirCanje($this->canje(self::P1, 500, $ids))->assertStatus(409)->assertJsonPath('error.codigo', 'TICKET_EXPIRADO');
        $this->assertSinEfectos();
    }

    public function test_un_segundo_antes_de_expirar_todavia_sirve(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));
        $this->travelTo(Carbon::createFromTimestampUTC(self::T0 + 2592000 - 1));

        $this->pedirCanje($this->canje(self::P1, 500, $ids))->assertStatus(200);
    }

    public function test_si_la_suma_no_coincide_con_el_monto_se_rechaza_sin_cambio(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1, 100 => 1]));

        $this->pedirCanje($this->canje(self::P1, 500, $ids))->assertStatus(422)->assertJsonPath('error.codigo', 'MONTO_INCORRECTO');
        $this->pedirCanje($this->canje(self::P1, 700, $ids))->assertStatus(422)->assertJsonPath('error.codigo', 'MONTO_INCORRECTO');
        $this->assertSinEfectos();
    }

    public function test_E1_un_id_que_el_banco_nunca_emitio_es_ticket_invalido(): void
    {
        $this->comprar('ana', [500 => 1]);
        $falso = '99999999-9999-4999-8999-999999999999';

        $this->pedirCanje($this->canje(self::P1, 500, [$falso]))->assertStatus(403)->assertJsonPath('error.codigo', 'TICKET_INVALIDO');
        $this->assertSinEfectos();
    }

    public function test_E1_mezclar_un_ticket_real_con_uno_falso_no_gasta_el_real(): void
    {
        $real = $this->ids($this->comprar('ana', [500 => 1]));

        $this->pedirCanje($this->canje(self::P1, 1000, [...$real, '99999999-9999-4999-8999-999999999999']))
            ->assertStatus(403)->assertJsonPath('error.codigo', 'TICKET_INVALIDO');
        $this->assertSinEfectos();
    }

    public function test_tickets_de_dos_cuentas_distintas_no_se_pueden_juntar(): void
    {
        $a = $this->ids($this->comprar('ana', [500 => 1]));
        $b = $this->ids($this->comprar('beto', [500 => 1]));

        $this->pedirCanje($this->canje(self::P1, 1000, [...$a, ...$b]))->assertStatus(403)->assertJsonPath('error.codigo', 'TICKET_INVALIDO');
        $this->assertSinEfectos();
    }

    public function test_beto_no_puede_gastar_los_tickets_de_ana_con_su_propia_firma(): void
    {
        $a = $this->ids($this->comprar('ana', [500 => 1]));

        $this->pedirCanje($this->canje(self::P1, 500, $a, [], ['firmaU' => $this->firmanteB]))
            ->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
        $this->assertSinEfectos();
    }

    // ---------------- E3: firmas ----------------

    public function test_E3_una_firma_de_la_tienda_alterada_se_rechaza_sin_efectos(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));
        $f = $this->canje(self::P1, 500, $ids);
        $b = base64_decode($f['firma_t'], true);
        $b[10] = chr(ord($b[10]) ^ 0x01);
        $f['firma_t'] = base64_encode($b);

        $this->pedirCanje($f)->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');

        $this->assertSinEfectos();
        $this->assertSame(0, Nonce::where('endpoint', '/api/canje')->count(), 'el nonce no se guarda si la firma falló');
    }

    public function test_E3_un_mensaje_firmado_por_otra_llave_no_vale_como_de_la_tienda(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));

        $this->pedirCanje($this->canje(self::P1, 500, $ids, [], ['firmaT' => new CryptoService(self::$llaves['intruso'][0])]))
            ->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
        $this->assertSinEfectos();
    }

    public function test_E3_cambiar_el_monto_despues_de_firmar_invalida_la_firma_de_la_tienda(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));

        $this->pedirCanje($this->canje(self::P1, 500, $ids, ['monto' => 20]))->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
        $this->assertSinEfectos();
    }

    public function test_E3_cambiar_los_ids_despues_de_firmar_invalida_la_firma_de_la_tienda(): void
    {
        $a = $this->ids($this->comprar('ana', [500 => 1]));
        $b = $this->ids($this->comprar('ana', [500 => 1]));

        $this->pedirCanje($this->canje(self::P1, 500, $a, ['ids' => $b]))->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
        $this->assertSinEfectos();
    }

    public function test_E3_una_orden_firmada_por_el_cliente_con_otro_monto_se_rechaza(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));
        $f = $this->canje(self::P1, 500, $ids);
        // la orden se firmó para 20 pesos, pero la tienda (con firma propia válida) intenta canjear 500
        $f['orden']['firma_u'] = $this->firmanteA->firmar(Canonico::orden(self::P1, 20, $ids, $f['orden']['nonce'], $f['orden']['timestamp']));

        $this->pedirCanje($f)->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
        $this->assertSinEfectos();
    }

    public function test_E3_una_orden_alterada_en_su_nonce_se_rechaza(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));
        $f = $this->canje(self::P1, 500, $ids);
        $f['orden']['nonce'] = $this->cliente->nonce();

        $this->pedirCanje($f)->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
    }

    public function test_E3_la_firma_de_una_consulta_no_sirve_como_firma_de_canje(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));
        $f = $this->canje(self::P1, 500, $ids);
        $f['firma_t'] = $this->tienda->firmar(Canonico::consultaCanje(self::P1, $f['nonce'], $f['timestamp']));

        $this->pedirCanje($f)->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
    }

    public function test_un_cert_u_vencido_se_rechaza(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));
        Cuenta::query()->update(['cert_expiracion' => self::T0]);

        $this->pedirCanje($this->canje(self::P1, 500, $ids))->assertStatus(403)->assertJsonPath('error.codigo', 'CERTIFICADO_INVALIDO');
        $this->assertSinEfectos();
    }

    // ---------------- Orden de verificación ----------------

    public function test_la_firma_se_verifica_antes_que_las_reglas_de_negocio(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));
        $this->pedirCanje($this->canje(self::P1, 500, $ids))->assertStatus(200);

        // Ticket ya gastado Y firma inválida: gana la firma (no se revela el estado a quien no está autorizado)
        $this->pedirCanje($this->canje(self::P2, 500, $ids, [], ['firmaT' => new CryptoService(self::$llaves['intruso'][0])]))
            ->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
    }

    public function test_el_formato_se_valida_antes_que_las_firmas(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));
        $f = $this->canje(self::P1, 500, $ids, ['firma_t' => 'no es base64 ***']);

        $this->pedirCanje($f)->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');
    }

    // ---------------- Nonce, timestamp, sobre y formato ----------------

    public function test_repetir_el_mismo_mensaje_cifrado_es_nonce_repetido(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));
        [$sobre] = $this->cliente->crearSobre(json_encode($this->canje(self::P1, 500, $ids)), self::$llaves['rsa'][1]);

        $this->postJson('/api/canje', $sobre)->assertStatus(200);
        $this->postJson('/api/canje', $sobre)->assertStatus(409)->assertJsonPath('error.codigo', 'NONCE_REPETIDO');
        $this->assertSame(1, Canje::count());
    }

    public function test_un_nonce_ya_usado_en_otro_mensaje_se_rechaza(): void
    {
        $a = $this->ids($this->comprar('ana', [500 => 1]));
        $b = $this->ids($this->comprar('ana', [500 => 1]));
        $nonce = $this->cliente->nonce();
        $this->pedirCanje($this->canje(self::P1, 500, $a, [], ['nonce' => $nonce]))->assertStatus(200);

        $this->pedirCanje($this->canje(self::P2, 500, $b, [], ['nonce' => $nonce]))->assertStatus(409)->assertJsonPath('error.codigo', 'NONCE_REPETIDO');
        $this->assertNull(Canje::find(self::P2));
    }

    public function test_el_timestamp_de_la_tienda_se_valida_con_tolerancia_de_120_segundos(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));

        $this->pedirCanje($this->canje(self::P1, 500, $ids, [], ['ts' => self::T0 + 121]))->assertStatus(400)->assertJsonPath('error.codigo', 'TIMESTAMP_FUERA_DE_RANGO');
        $this->pedirCanje($this->canje(self::P1, 500, $ids, [], ['ts' => self::T0 - 121]))->assertStatus(400)->assertJsonPath('error.codigo', 'TIMESTAMP_FUERA_DE_RANGO');
        $this->assertSinEfectos();
        $this->pedirCanje($this->canje(self::P1, 500, $ids, [], ['ts' => self::T0 + 120]))->assertStatus(200);
    }

    public function test_un_sobre_alterado_se_rechaza(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));

        [$r] = $this->enviar('/api/canje', $this->canje(self::P1, 500, $ids), null, true);

        $r->assertStatus(400)->assertJsonPath('error.codigo', 'MENSAJE_CORRUPTO');
        $this->assertSinEfectos();
    }

    public function test_formatos_invalidos_se_rechazan(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));
        $base = $this->canje(self::P1, 500, $ids);
        $casos = [
            'id_pedido no UUID' => ['id_pedido' => 'abc'],
            'monto texto' => ['monto' => '500'],
            'monto cero' => ['monto' => 0],
            'monto decimal' => ['monto' => 500.5],
            'ids vacío' => ['ids' => []],
            'ids no es lista' => ['ids' => ['a' => $ids[0]]],
            'ids repetidos' => ['ids' => [$ids[0], $ids[0]]],
            'id no UUID' => ['ids' => ['xyz']],
            'más de 50 ids' => ['ids' => array_fill(0, 51, $ids[0])],
            'orden ausente' => ['orden' => null],
            'orden sin firma' => ['orden' => ['nonce' => $base['orden']['nonce'], 'timestamp' => 1]],
            'orden con campo extra' => ['orden' => $base['orden'] + ['extra' => 1]],
            'orden nonce corto' => ['orden' => ['nonce' => 'abc'] + $base['orden']],
            'firma_t ausente' => ['firma_t' => null],
        ];
        foreach ($casos as $nombre => $cambio) {
            $this->pedirCanje(array_merge($base, $cambio))->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO', $nombre);
        }
        $this->assertSinEfectos();
    }

    // ---------------- Consulta de canje ----------------

    public function test_la_consulta_devuelve_el_recibo_original(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1, 20 => 1]));
        [$r, $k] = $this->enviar('/api/canje', $this->canje(self::P1, 520, $ids));
        $original = $this->abrir($r, $k)['recibo'];
        $this->travelTo(Carbon::createFromTimestampUTC(self::T0 + 60));

        [$c, $kc] = $this->enviar('/api/canjes/consulta', $this->consultaCuerpo(self::P1));

        $c->assertStatus(200);
        $this->assertSame($original, $this->abrir($c, $kc)['recibo']);
    }

    /** Cuerpo de consulta firmado por la tienda. */
    private function consultaCuerpo(string $idPedido): array
    {
        $nonce = $this->cliente->nonce();
        $ts = now()->timestamp;

        return ['id_pedido' => $idPedido, 'nonce' => $nonce, 'timestamp' => $ts,
            'firma_t' => $this->tienda->firmar(Canonico::consultaCanje($idPedido, $nonce, $ts))];
    }

    public function test_consultar_un_pedido_sin_canje_es_404(): void
    {
        $this->consulta(self::P1)->assertStatus(404)->assertJsonPath('error.codigo', 'CANJE_NO_ENCONTRADO');
    }

    public function test_consulta_con_firma_invalida_se_rechaza_aunque_el_canje_exista(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));
        $this->pedirCanje($this->canje(self::P1, 500, $ids))->assertStatus(200);

        $this->consulta(self::P1, [], new CryptoService(self::$llaves['intruso'][0]))->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
        $this->consulta(self::P1, ['id_pedido' => self::P2])->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
    }

    public function test_la_consulta_no_puede_repetirse_con_el_mismo_mensaje(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));
        $this->pedirCanje($this->canje(self::P1, 500, $ids))->assertStatus(200);
        [$sobre] = $this->cliente->crearSobre(json_encode($this->consultaCuerpo(self::P1)), self::$llaves['rsa'][1]);

        $this->postJson('/api/canjes/consulta', $sobre)->assertStatus(200);
        $this->postJson('/api/canjes/consulta', $sobre)->assertStatus(409)->assertJsonPath('error.codigo', 'NONCE_REPETIDO');
    }

    public function test_la_consulta_con_formato_invalido_se_rechaza(): void
    {
        $this->consulta('no-uuid')->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');
    }

    // ---------------- Bitácora ----------------

    public function test_la_bitacora_registra_canjes_y_rechazos_sin_firmas_ni_ids_completos(): void
    {
        $ids = $this->ids($this->comprar('ana', [500 => 1]));
        $f = $this->canje(self::P1, 500, $ids);
        $this->pedirCanje($f)->assertStatus(200);
        $this->pedirCanje($this->canje(self::P2, 500, $ids))->assertStatus(409);
        $this->pedirCanje($this->canje(self::P2, 500, $ids, [], ['firmaT' => new CryptoService(self::$llaves['intruso'][0])]))->assertStatus(403);

        $texto = json_encode(Bitacora::all()->toArray());
        $this->assertStringContainsString('Canje 11111111 por $500', $texto);
        $this->assertStringContainsString('TICKET_GASTADO', $texto);
        $this->assertStringContainsString('FIRMA_INVALIDA', $texto);
        $this->assertStringNotContainsString($f['firma_t'], $texto);
        $this->assertStringNotContainsString($f['orden']['firma_u'], $texto);
        $this->assertStringNotContainsString($ids[0], $texto);
    }
}
