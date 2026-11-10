<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Cuenta;
use App\Models\Nonce;
use App\Services\Canonico;
use App\Services\CryptoService;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Fase 5: registro, login y consulta de saldo del Banco, de punta a punta (petición cifrada -> respuesta).
 * Las llaves del servidor y del cliente son temporales; el reloj se congela en T0.
 */
class AutenticacionBancoTest extends TestCase
{
    use RefreshDatabase;

    private const T0 = 1762500000;
    private const PW = 'ClaveSegura-1';

    private static ?array $llaves = null;   // se generan una sola vez para todas las pruebas
    private CryptoService $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::createFromTimestampUTC(self::T0));

        // Argon2id con parámetros mínimos: solo para que las pruebas sean rápidas.
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1, 'hashing.argon.threads' => 1]);

        self::$llaves ??= [
            'ec' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
            'rsa' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]),
            'cliente' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
            'otraRsa' => $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]),
        ];

        // El servidor de la prueba usa las llaves temporales, no las de storage/app/keys.
        $this->app->instance(CryptoService::class, new CryptoService(self::$llaves['ec'][0], self::$llaves['rsa'][0]));
        $this->app->instance(JwtService::class, new JwtService(str_repeat('s', 40), 900));
        $this->cliente = new CryptoService();
    }

    // ---------------- Ayudas ----------------

    /** @return array{0:string,1:string} [privada PEM, pública PEM] */
    private function nuevaLlave(array $opciones): array
    {
        $r = openssl_pkey_new($opciones);
        openssl_pkey_export($r, $privada);

        return [$privada, openssl_pkey_get_details($r)['key']];
    }

    /** Llave pública PEM -> Base64 del DER (lo mismo que publicKey.encoded en Android). */
    private function base64DeLlave(string $pem): string
    {
        return preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
    }

    private function pubU(): string
    {
        return $this->base64DeLlave(self::$llaves['cliente'][1]);
    }

    private function ahora(): int
    {
        return now()->timestamp;
    }

    /** Cifra, envía y devuelve [respuesta HTTP, K]. */
    private function enviar(string $ruta, array $cuerpo, ?string $token = null, bool $alterar = false, ?string $rsaPub = null): array
    {
        [$sobre, $k] = $this->cliente->crearSobre(json_encode($cuerpo), $rsaPub ?? self::$llaves['rsa'][1]);
        if ($alterar) {
            $ct = base64_decode($sobre['ct'], true);
            $ct[0] = chr(ord($ct[0]) ^ 0x01);   // cambia un solo bit
            $sobre['ct'] = base64_encode($ct);
        }

        return [$this->postJson($ruta, $sobre, $token ? ['Authorization' => "Bearer $token"] : []), $k];
    }

    /** Descifra la respuesta exitosa con la K de la petición. */
    private function abrir(TestResponse $resp, string $k): array
    {
        return json_decode($this->cliente->abrirRespuesta($resp->json(), $k), true);
    }

    private function registrar(string $correo = 'ana@ejemplo.com'): array
    {
        [$r, $k] = $this->enviar('/api/registro', ['correo' => $correo, 'password' => self::PW, 'pub_u' => $this->pubU()]);
        $r->assertStatus(201);

        return $this->abrir($r, $k);
    }

    private function iniciarSesion(string $correo = 'ana@ejemplo.com', string $password = self::PW): array
    {
        [$r, $k] = $this->enviar('/api/login', ['correo' => $correo, 'password' => $password]);
        $r->assertStatus(200);

        return $this->abrir($r, $k);
    }

    private function consultar(?string $token, array $cuerpo): TestResponse
    {
        return $this->enviar('/api/cuenta/consulta', $cuerpo, $token)[0];
    }

    private function datosConsulta(array $cambios = []): array
    {
        return array_merge(['nonce' => $this->cliente->nonce(), 'timestamp' => $this->ahora()], $cambios);
    }

    // ---------------- Configuración del entorno ----------------

    public function test_el_secreto_jwt_del_env_tiene_al_menos_32_caracteres(): void
    {
        $this->assertGreaterThanOrEqual(32, strlen((string) config('seguridad.jwt_secret')), 'Corrige JWT_SECRET en banco/.env');
    }

    // ---------------- Registro ----------------

    public function test_registro_crea_la_cuenta_y_responde_cifrado(): void
    {
        [$r, $k] = $this->enviar('/api/registro', ['correo' => 'ana@ejemplo.com', 'password' => self::PW, 'pub_u' => $this->pubU()]);

        $r->assertStatus(201)->assertJsonStructure(['iv', 'ct'])->assertJsonMissingPath('saldo')->assertJsonMissingPath('id_cuenta');
        $datos = $this->abrir($r, $k);
        $this->assertSame(10000, $datos['saldo']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $datos['id_cuenta']);
        $this->assertSame(1, Cuenta::count());
    }

    public function test_el_cert_u_esta_firmado_por_el_banco(): void
    {
        $cert = $this->registrar()['cert_u'];

        $cadena = Canonico::cert($cert['id_cuenta'], $cert['pub_u'], $cert['expiracion']);
        $this->assertTrue($this->cliente->verificar($cadena, $cert['firma_b'], self::$llaves['ec'][1]));
        $this->assertSame($this->pubU(), $cert['pub_u']);
        $this->assertSame(self::T0 + 31536000, $cert['expiracion']);   // 365 días
        $this->assertFalse($this->cliente->verificar($cadena . 'x', $cert['firma_b'], self::$llaves['ec'][1]));
    }

    public function test_la_contrasena_se_guarda_como_hash_argon2id_y_el_correo_en_minusculas(): void
    {
        $this->registrar('Ana@Ejemplo.COM');

        $c = Cuenta::first();
        $this->assertSame('ana@ejemplo.com', $c->correo);
        $this->assertNotSame(self::PW, $c->password_hash);
        $this->assertStringStartsWith('$argon2id$', $c->password_hash);
    }

    public function test_correo_repetido_responde_409_aunque_cambie_la_capitalizacion(): void
    {
        $this->registrar('ana@ejemplo.com');

        [$r] = $this->enviar('/api/registro', ['correo' => 'ANA@ejemplo.com', 'password' => self::PW, 'pub_u' => $this->pubU()]);

        $r->assertStatus(409)->assertJsonPath('error.codigo', 'CORREO_EN_USO');
        $this->assertSame(1, Cuenta::count());
    }

    public function test_registro_rechaza_pub_u_que_no_es_base64(): void
    {
        [$r] = $this->enviar('/api/registro', ['correo' => 'ana@ejemplo.com', 'password' => self::PW, 'pub_u' => 'no-es-base64!!']);

        $r->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');
    }

    public function test_registro_rechaza_una_llave_que_no_es_ec_p256(): void
    {
        [$r] = $this->enviar('/api/registro', ['correo' => 'ana@ejemplo.com', 'password' => self::PW, 'pub_u' => $this->base64DeLlave(self::$llaves['otraRsa'][1])]);

        $r->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');
    }

    public function test_registro_rechaza_contrasena_corta_y_correo_invalido(): void
    {
        [$r1] = $this->enviar('/api/registro', ['correo' => 'ana@ejemplo.com', 'password' => 'corta', 'pub_u' => $this->pubU()]);
        [$r2] = $this->enviar('/api/registro', ['correo' => 'no-es-correo', 'password' => self::PW, 'pub_u' => $this->pubU()]);
        [$r3] = $this->enviar('/api/registro', ['password' => self::PW, 'pub_u' => $this->pubU()]);   // falta el correo

        foreach ([$r1, $r2, $r3] as $r) {
            $r->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');
        }
        $this->assertSame(0, Cuenta::count());
    }

    // ---------------- E4: mensaje cifrado modificado ----------------

    public function test_E4_un_sobre_alterado_se_rechaza_y_no_se_ejecuta(): void
    {
        [$r] = $this->enviar('/api/registro', ['correo' => 'ana@ejemplo.com', 'password' => self::PW, 'pub_u' => $this->pubU()], null, true);

        $r->assertStatus(400)->assertJsonPath('error.codigo', 'MENSAJE_CORRUPTO');
        $this->assertSame(0, Cuenta::count());
        $this->assertSame(1, Bitacora::where('resultado', 'RECHAZADO')->where('codigo', 'MENSAJE_CORRUPTO')->count());
    }

    public function test_E4_un_sobre_cifrado_para_otra_llave_rsa_se_rechaza(): void
    {
        [$r] = $this->enviar('/api/registro', ['correo' => 'ana@ejemplo.com', 'password' => self::PW, 'pub_u' => $this->pubU()], null, false, self::$llaves['otraRsa'][1]);

        $r->assertStatus(400)->assertJsonPath('error.codigo', 'MENSAJE_CORRUPTO');
    }

    public function test_una_peticion_sin_sobre_se_rechaza(): void
    {
        $this->postJson('/api/registro', ['correo' => 'ana@ejemplo.com'])->assertStatus(400)->assertJsonPath('error.codigo', 'MENSAJE_CORRUPTO');
    }

    // ---------------- Login ----------------

    public function test_login_correcto_entrega_token_bearer_cifrado(): void
    {
        $reg = $this->registrar();
        [$r, $k] = $this->enviar('/api/login', ['correo' => 'ANA@ejemplo.com', 'password' => self::PW]);

        $r->assertStatus(200)->assertJsonStructure(['iv', 'ct'])->assertJsonMissingPath('token');
        $d = $this->abrir($r, $k);
        $this->assertSame('Bearer', $d['tipo']);
        $this->assertSame(900, $d['expira_en']);
        $this->assertSame($reg['id_cuenta'], $d['id_cuenta']);
        $this->assertSame(10000, $d['saldo']);
        $this->assertSame($reg['cert_u'], $d['cert_u']);
        $this->assertSame($reg['id_cuenta'], app(JwtService::class)->validar($d['token'], self::T0 + 1));
    }

    public function test_E5_contrasena_incorrecta_y_correo_inexistente_dan_la_misma_respuesta(): void
    {
        $this->registrar();

        [$r1] = $this->enviar('/api/login', ['correo' => 'ana@ejemplo.com', 'password' => 'incorrecta-xx']);
        [$r2] = $this->enviar('/api/login', ['correo' => 'nadie@ejemplo.com', 'password' => 'incorrecta-xx']);

        $r1->assertStatus(401)->assertJsonPath('error.codigo', 'CREDENCIALES_INVALIDAS');
        $r2->assertStatus(401)->assertJsonPath('error.codigo', 'CREDENCIALES_INVALIDAS');
        $this->assertSame($r1->json(), $r2->json());   // mismo código y mismo mensaje
    }

    public function test_E5_cinco_fallos_bloquean_aunque_despues_se_use_la_contrasena_correcta(): void
    {
        $this->registrar();

        for ($i = 1; $i <= 5; $i++) {
            [$r] = $this->enviar('/api/login', ['correo' => 'ana@ejemplo.com', 'password' => "incorrecta-$i-xx"]);
            $r->assertStatus(401)->assertJsonPath('error.codigo', 'CREDENCIALES_INVALIDAS');
        }
        [$r] = $this->enviar('/api/login', ['correo' => 'ana@ejemplo.com', 'password' => self::PW]);

        $r->assertStatus(429)->assertJsonPath('error.codigo', 'DEMASIADOS_INTENTOS');
    }

    public function test_E5_la_cuenta_se_desbloquea_a_los_300_segundos(): void
    {
        $this->registrar();
        for ($i = 1; $i <= 5; $i++) {
            $this->enviar('/api/login', ['correo' => 'ana@ejemplo.com', 'password' => "incorrecta-$i-xx"]);
        }

        $this->travelTo(Carbon::createFromTimestampUTC(self::T0 + 299));
        [$r] = $this->enviar('/api/login', ['correo' => 'ana@ejemplo.com', 'password' => self::PW]);
        $r->assertStatus(429);

        $this->travelTo(Carbon::createFromTimestampUTC(self::T0 + 300));
        [$r] = $this->enviar('/api/login', ['correo' => 'ana@ejemplo.com', 'password' => self::PW]);
        $r->assertStatus(200);
    }

    public function test_un_login_correcto_reinicia_el_contador_de_fallos(): void
    {
        $this->registrar();
        for ($i = 1; $i <= 4; $i++) {
            $this->enviar('/api/login', ['correo' => 'ana@ejemplo.com', 'password' => "incorrecta-$i-xx"]);
        }
        $this->assertSame(4, Cuenta::first()->intentos_fallidos);

        $this->iniciarSesion();

        $this->assertSame(0, Cuenta::first()->intentos_fallidos);
    }

    // ---------------- Consulta de saldo ----------------

    public function test_consulta_con_token_devuelve_el_saldo_cifrado(): void
    {
        $this->registrar();
        $token = $this->iniciarSesion()['token'];

        [$r, $k] = $this->enviar('/api/cuenta/consulta', $this->datosConsulta(), $token);

        $r->assertStatus(200)->assertJsonMissingPath('saldo');
        $this->assertSame(10000, $this->abrir($r, $k)['saldo']);
    }

    public function test_consulta_sin_token_o_con_token_alterado_da_TOKEN_INVALIDO(): void
    {
        $this->registrar();
        $token = $this->iniciarSesion()['token'];

        $this->consultar(null, $this->datosConsulta())->assertStatus(401)->assertJsonPath('error.codigo', 'TOKEN_INVALIDO');
        $this->consultar($token . 'x', $this->datosConsulta())->assertStatus(401)->assertJsonPath('error.codigo', 'TOKEN_INVALIDO');
        $this->consultar('abc.def.ghi', $this->datosConsulta())->assertStatus(401)->assertJsonPath('error.codigo', 'TOKEN_INVALIDO');
    }

    public function test_un_token_vencido_da_TOKEN_INVALIDO(): void
    {
        $this->registrar();
        $token = $this->iniciarSesion()['token'];

        $this->travelTo(Carbon::createFromTimestampUTC(self::T0 + 900));

        $this->consultar($token, $this->datosConsulta())->assertStatus(401)->assertJsonPath('error.codigo', 'TOKEN_INVALIDO');
    }

    public function test_el_timestamp_fuera_de_120_segundos_se_rechaza(): void
    {
        $this->registrar();
        $token = $this->iniciarSesion()['token'];

        $this->consultar($token, $this->datosConsulta(['timestamp' => self::T0 - 121]))->assertStatus(400)->assertJsonPath('error.codigo', 'TIMESTAMP_FUERA_DE_RANGO');
        $this->consultar($token, $this->datosConsulta(['timestamp' => self::T0 + 121]))->assertStatus(400)->assertJsonPath('error.codigo', 'TIMESTAMP_FUERA_DE_RANGO');
        $this->consultar($token, $this->datosConsulta(['timestamp' => self::T0 - 120]))->assertStatus(200);   // el límite es válido
    }

    public function test_un_nonce_repetido_se_rechaza(): void
    {
        $this->registrar();
        $token = $this->iniciarSesion()['token'];
        $datos = $this->datosConsulta();

        $this->consultar($token, $datos)->assertStatus(200);
        $this->consultar($token, $datos)->assertStatus(409)->assertJsonPath('error.codigo', 'NONCE_REPETIDO');
    }

    public function test_nonce_y_timestamp_con_formato_incorrecto_se_rechazan(): void
    {
        $this->registrar();
        $token = $this->iniciarSesion()['token'];

        $this->consultar($token, $this->datosConsulta(['nonce' => 'ABC']))->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');
        $this->consultar($token, $this->datosConsulta(['timestamp' => (string) self::T0]))->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');
        $this->consultar($token, ['timestamp' => self::T0])->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');
    }

    // ---------------- Orden de verificación (contrato, sección 13) ----------------

    public function test_orden_el_sobre_corrupto_se_detecta_antes_que_el_token(): void
    {
        [$r] = $this->enviar('/api/cuenta/consulta', $this->datosConsulta(), null, true);

        $r->assertJsonPath('error.codigo', 'MENSAJE_CORRUPTO');
    }

    public function test_orden_el_token_se_valida_antes_que_formato_y_timestamp(): void
    {
        $r = $this->consultar('abc.def.ghi', ['nonce' => 'mal', 'timestamp' => 5]);

        $r->assertJsonPath('error.codigo', 'TOKEN_INVALIDO');
    }

    public function test_orden_con_un_rechazo_previo_el_nonce_no_se_guarda(): void
    {
        $this->consultar('abc.def.ghi', $this->datosConsulta());                                       // TOKEN_INVALIDO
        $this->registrar();
        $token = $this->iniciarSesion()['token'];
        $this->consultar($token, $this->datosConsulta(['timestamp' => self::T0 - 500]));               // TIMESTAMP_FUERA_DE_RANGO

        $this->assertSame(0, Nonce::count());
    }

    // ---------------- Bitácora ----------------

    public function test_la_bitacora_registra_aceptados_y_rechazados_sin_secretos(): void
    {
        $this->registrar();
        $token = $this->iniciarSesion()['token'];
        $this->enviar('/api/login', ['correo' => 'ana@ejemplo.com', 'password' => 'secreta-equivocada']);
        $this->consultar('abc.def.ghi', $this->datosConsulta());

        $filas = Bitacora::all();
        $this->assertGreaterThan(0, $filas->where('resultado', 'ACEPTADO')->count());
        $this->assertGreaterThan(0, $filas->where('resultado', 'RECHAZADO')->count());
        $this->assertNotNull($filas->firstWhere('codigo', 'CREDENCIALES_INVALIDAS'));
        $this->assertNotNull($filas->firstWhere('codigo', 'TOKEN_INVALIDO'));

        $texto = json_encode($filas->toArray());
        foreach ([self::PW, 'secreta-equivocada', $token] as $secreto) {
            $this->assertStringNotContainsString($secreto, $texto);
        }
    }
}
