<?php

namespace App\Console\Commands;

use App\Services\Canonico;
use App\Services\CryptoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Simula a la app Android contra el servidor Banco YA EN EJECUCIÓN: registro, login, consulta
 * y los escenarios E4 y E5. Sirve para probar a mano sin la app y como apoyo en la demostración.
 */
class ClienteDemo extends Command
{
    protected $signature = 'cliente:demo {--url=http://localhost:8001 : URL del servidor Banco}';
    protected $description = 'Simula una app cliente contra el Banco (registro, login, consulta, E4 y E5)';

    private CryptoService $cripto;
    private string $rsaPubBanco;
    private string $ecPubBanco;
    private string $url;
    private int $fallos = 0;

    public function handle(): int
    {
        $this->url = rtrim((string) $this->option('url'), '/');
        $this->cripto = new CryptoService();   // el cliente no necesita llaves privadas para cifrar sobres
        $this->rsaPubBanco = (string) @file_get_contents(base_path('../claves-publicas/banco_rsa_pub.pem'));
        $this->ecPubBanco = (string) @file_get_contents(base_path('../claves-publicas/banco_ec_pub.pem'));
        if ($this->rsaPubBanco === '' || $this->ecPubBanco === '') {
            $this->error('No se encontraron las llaves públicas del banco en ../claves-publicas/');

            return self::FAILURE;
        }

        // Llave del cliente: un par EC P-256 nuevo (la app real usa el Android Keystore).
        $par = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = openssl_pkey_get_details($par)['key'];
        $pubU = base64_encode(base64_decode(trim(preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem)), true));

        $correo = 'demo' . bin2hex(random_bytes(3)) . '@ejemplo.com';
        $password = 'ClaveDemo-2026';

        $this->info("== 1. Registro ($correo) ==");
        [$estado, $r] = $this->enviar('/api/registro', ['correo' => $correo, 'password' => $password, 'pub_u' => $pubU]);
        $this->comprobar('registro responde 201', $estado === 201);
        $this->comprobar('saldo inicial 10000', ($r['saldo'] ?? null) === 10000);
        $cert = $r['cert_u'] ?? [];
        $cadena = Canonico::cert($cert['id_cuenta'] ?? '', $cert['pub_u'] ?? '', (int) ($cert['expiracion'] ?? 0));
        $this->comprobar('firma del banco en Cert_U válida', $this->cripto->verificar($cadena, $cert['firma_b'] ?? '', $this->ecPubBanco));

        $this->info('== 2. Login ==');
        [$estado, $r] = $this->enviar('/api/login', ['correo' => $correo, 'password' => $password]);
        $this->comprobar('login responde 200', $estado === 200);
        $this->comprobar('trae token Bearer de 900 s', ($r['tipo'] ?? null) === 'Bearer' && ($r['expira_en'] ?? null) === 900 && !empty($r['token']));
        $token = $r['token'] ?? '';

        $this->info('== 3. Consulta de saldo ==');
        $nonce = $this->cripto->nonce();
        [$estado, $r] = $this->enviar('/api/cuenta/consulta', ['nonce' => $nonce, 'timestamp' => time()], $token);
        $this->comprobar('consulta responde 200 con saldo 10000', $estado === 200 && ($r['saldo'] ?? null) === 10000);

        $this->info('== 4. Repetición del mismo nonce ==');
        [$estado, $r] = $this->enviar('/api/cuenta/consulta', ['nonce' => $nonce, 'timestamp' => time()], $token);
        $this->comprobar('rechazado con NONCE_REPETIDO (409)', $estado === 409 && ($r['error']['codigo'] ?? '') === 'NONCE_REPETIDO');

        $this->info('== 5. E4: mensaje cifrado modificado ==');
        [$estado, $r] = $this->enviar('/api/login', ['correo' => $correo, 'password' => $password], null, true);
        $this->comprobar('rechazado con MENSAJE_CORRUPTO (400)', $estado === 400 && ($r['error']['codigo'] ?? '') === 'MENSAJE_CORRUPTO');

        $this->info('== 6. E5: credenciales inválidas (5 fallos y bloqueo) ==');
        for ($i = 1; $i <= 5; $i++) {
            [$estado, $r] = $this->enviar('/api/login', ['correo' => $correo, 'password' => "incorrecta-$i-xx"]);
            $this->comprobar("intento $i: CREDENCIALES_INVALIDAS (401)", $estado === 401 && ($r['error']['codigo'] ?? '') === 'CREDENCIALES_INVALIDAS');
        }
        [$estado, $r] = $this->enviar('/api/login', ['correo' => $correo, 'password' => $password]);
        $this->comprobar('intento 6 con la contraseña CORRECTA: DEMASIADOS_INTENTOS (429)', $estado === 429 && ($r['error']['codigo'] ?? '') === 'DEMASIADOS_INTENTOS');

        $this->newLine();
        if ($this->fallos === 0) {
            $this->info('TODO CORRECTO. Revisa la bitácora con: php artisan bitacora:ver');

            return self::SUCCESS;
        }
        $this->error("{$this->fallos} comprobación(es) fallaron.");

        return self::FAILURE;
    }

    /**
     * Cifra y envía un mensaje. Devuelve [estado HTTP, cuerpo]: el cuerpo de una respuesta exitosa se
     * descifra con la K de la petición; el de un error llega en claro.
     * @return array{0:int,1:array}
     */
    private function enviar(string $ruta, array $cuerpo, ?string $token = null, bool $alterar = false): array
    {
        [$sobre, $k] = $this->cripto->crearSobre(json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->rsaPubBanco);
        if ($alterar) {
            $ct = base64_decode($sobre['ct'], true);
            $ct[0] = chr(ord($ct[0]) ^ 0x01);   // cambia un solo bit del cifrado
            $sobre['ct'] = base64_encode($ct);
        }
        $peticion = Http::acceptJson()->asJson();
        if ($token !== null) {
            $peticion = $peticion->withToken($token);
        }
        $resp = $peticion->post($this->url . $ruta, $sobre);
        $json = $resp->json() ?? [];

        if ($resp->successful() && isset($json['iv'], $json['ct'])) {
            return [$resp->status(), json_decode($this->cripto->abrirRespuesta($json, $k), true) ?? []];
        }

        return [$resp->status(), $json];
    }

    private function comprobar(string $texto, bool $ok): void
    {
        $this->line(($ok ? '  [OK]    ' : '  [FALLO] ') . $texto);
        if (!$ok) {
            $this->fallos++;
        }
    }
}
