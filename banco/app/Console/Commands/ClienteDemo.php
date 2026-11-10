<?php

namespace App\Console\Commands;

use App\Services\Canonico;
use App\Services\CryptoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Simula a la app Android contra el servidor Banco YA EN EJECUCIÓN: registro, login, consulta,
 * solicitud de tickets y los escenarios E1, E3, E4 y E5 (más repetición de mensajes y saldo).
 * Sirve para probar a mano sin la app y como apoyo en la demostración.
 */
class ClienteDemo extends Command
{
    protected $signature = 'cliente:demo {--url=http://localhost:8001 : URL del servidor Banco}';
    protected $description = 'Simula una app cliente contra el Banco (registro, login, tickets, E1, E3, E4 y E5)';

    private CryptoService $cripto;
    private CryptoService $firmante;
    private string $rsaPubBanco;
    private string $ecPubBanco;
    private string $url;
    private int $fallos = 0;

    public function handle(): int
    {
        $this->url = rtrim((string) $this->option('url'), '/');
        $this->rsaPubBanco = (string) @file_get_contents(base_path('../claves-publicas/banco_rsa_pub.pem'));
        $this->ecPubBanco = (string) @file_get_contents(base_path('../claves-publicas/banco_ec_pub.pem'));
        if ($this->rsaPubBanco === '' || $this->ecPubBanco === '') {
            $this->error('No se encontraron las llaves públicas del banco en ../claves-publicas/');

            return self::FAILURE;
        }

        // Llave del cliente: un par EC P-256 nuevo (la app real usa el Android Keystore).
        $par = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($par, $privada);
        $this->firmante = new CryptoService($privada);
        $this->cripto = new CryptoService();   // cifrar sobres no requiere llaves privadas
        $pubU = preg_replace('/-----[A-Z ]+-----|\s+/', '', openssl_pkey_get_details($par)['key']);

        $correo = 'demo' . bin2hex(random_bytes(3)) . '@ejemplo.com';
        $password = 'ClaveDemo-2026';

        $this->info("== 1. Registro ($correo) ==");
        [$estado, $r] = $this->enviar('/api/registro', ['correo' => $correo, 'password' => $password, 'pub_u' => $pubU]);
        $this->comprobar('registro responde 201 con saldo 10000', $estado === 201 && ($r['saldo'] ?? null) === 10000);
        $idCuenta = $r['id_cuenta'] ?? '';
        $cert = $r['cert_u'] ?? [];
        $cadena = Canonico::cert($cert['id_cuenta'] ?? '', $cert['pub_u'] ?? '', (int) ($cert['expiracion'] ?? 0));
        $this->comprobar('firma del banco en Cert_U válida', $this->cripto->verificar($cadena, $cert['firma_b'] ?? '', $this->ecPubBanco));

        $this->info('== 2. Login ==');
        [$estado, $r] = $this->enviar('/api/login', ['correo' => $correo, 'password' => $password]);
        $this->comprobar('login responde 200 con token Bearer de 900 s', $estado === 200 && ($r['tipo'] ?? null) === 'Bearer' && ($r['expira_en'] ?? null) === 900 && !empty($r['token']));
        $token = $r['token'] ?? '';

        $this->info('== 3. Consulta de saldo ==');
        $nonce = $this->cripto->nonce();
        [$estado, $r] = $this->enviar('/api/cuenta/consulta', ['nonce' => $nonce, 'timestamp' => time()], $token);
        $this->comprobar('saldo 10000', $estado === 200 && ($r['saldo'] ?? null) === 10000);

        $this->info('== 4. Solicitud de tickets: 2 de $500 y 1 de $300 ==');
        [$estado, $r] = $this->enviar('/api/tickets/solicitar', $this->solicitud($idCuenta, [500 => 2, 300 => 1]), $token);
        $this->comprobar('responde 201', $estado === 201);
        $tickets = $r['tickets'] ?? [];
        $this->comprobar('llegan 3 tickets y el saldo queda en 8700', count($tickets) === 3 && ($r['saldo'] ?? null) === 8700);
        $firmasOk = $tickets !== [];
        foreach ($tickets as $t) {
            $firmasOk = $firmasOk && $this->cripto->verificar(
                Canonico::ticket($t['id'], $t['denominacion'], $t['id_cuenta'], $t['emision'], $t['expiracion']), $t['firma_b'], $this->ecPubBanco
            );
        }
        $this->comprobar('la firma del banco en cada ticket es válida', $firmasOk);
        $rec = $r['recibo'] ?? [];
        $this->comprobar('recibo: total 1300 y firma del banco válida', ($rec['total'] ?? 0) === 1300 && $this->cripto->verificar(
            Canonico::reciboAdq($rec['id_cuenta'] ?? '', $rec['total'] ?? 0, $rec['ids'] ?? [], $rec['timestamp'] ?? 0), $rec['firma_b'] ?? '', $this->ecPubBanco
        ));

        $this->info('== 5. E1: ticket modificado (lo detecta la verificación de la firma del banco) ==');
        if ($tickets !== []) {
            $t = $tickets[0];
            $t['denominacion'] = 500 === $t['denominacion'] ? 300 : 500;   // un atacante cambia el valor
            $valido = $this->cripto->verificar(Canonico::ticket($t['id'], $t['denominacion'], $t['id_cuenta'], $t['emision'], $t['expiracion']), $t['firma_b'], $this->ecPubBanco);
            $this->comprobar('el ticket con la denominación cambiada NO verifica', !$valido);
        }

        $this->info('== 6. E3: firma del cliente alterada ==');
        $f = $this->solicitud($idCuenta, [100 => 1]);
        $bytes = base64_decode($f['firma_u']);
        $bytes[10] = chr(ord($bytes[10]) ^ 0x01);
        $f['firma_u'] = base64_encode($bytes);
        [$estado, $r] = $this->enviar('/api/tickets/solicitar', $f, $token);
        $this->comprobar('rechazado con FIRMA_INVALIDA (403)', $estado === 403 && ($r['error']['codigo'] ?? '') === 'FIRMA_INVALIDA');

        $this->info('== 7. E3: se firma una solicitud y se envía otra ==');
        $f = $this->solicitud($idCuenta, [100 => 1]);
        $f['denominaciones'] = [500 => 5];
        [$estado, $r] = $this->enviar('/api/tickets/solicitar', $f, $token);
        $this->comprobar('rechazado con FIRMA_INVALIDA (403)', $estado === 403 && ($r['error']['codigo'] ?? '') === 'FIRMA_INVALIDA');

        $this->info('== 8. Repetición del mismo mensaje cifrado ==');
        $f = $this->solicitud($idCuenta, [20 => 1]);
        [$sobre, $k] = $this->cripto->crearSobre(json_encode($f), $this->rsaPubBanco);
        [$e1] = $this->enviarSobre('/api/tickets/solicitar', $sobre, $k, $token);
        [$e2, $r] = $this->enviarSobre('/api/tickets/solicitar', $sobre, $k, $token);
        $this->comprobar('la primera vez: 201', $e1 === 201);
        $this->comprobar('la repetición: NONCE_REPETIDO (409)', $e2 === 409 && ($r['error']['codigo'] ?? '') === 'NONCE_REPETIDO');
        [$estado, $r] = $this->enviar('/api/cuenta/consulta', ['nonce' => $this->cripto->nonce(), 'timestamp' => time()], $token);
        $this->comprobar('el saldo se descontó una sola vez: 8680', ($r['saldo'] ?? null) === 8680);

        $this->info('== 9. Saldo insuficiente (30 tickets de $500 = $15000) ==');
        [$estado, $r] = $this->enviar('/api/tickets/solicitar', $this->solicitud($idCuenta, [500 => 30]), $token);
        $this->comprobar('rechazado con SALDO_INSUFICIENTE (422)', $estado === 422 && ($r['error']['codigo'] ?? '') === 'SALDO_INSUFICIENTE');

        $this->info('== 10. E4: mensaje cifrado modificado ==');
        [$estado, $r] = $this->enviar('/api/login', ['correo' => $correo, 'password' => $password], null, true);
        $this->comprobar('rechazado con MENSAJE_CORRUPTO (400)', $estado === 400 && ($r['error']['codigo'] ?? '') === 'MENSAJE_CORRUPTO');

        $this->info('== 11. E5: credenciales inválidas (5 fallos y bloqueo) ==');
        for ($i = 1; $i <= 5; $i++) {
            [$estado, $r] = $this->enviar('/api/login', ['correo' => $correo, 'password' => "incorrecta-$i-xx"]);
            $this->comprobar("intento $i: CREDENCIALES_INVALIDAS (401)", $estado === 401 && ($r['error']['codigo'] ?? '') === 'CREDENCIALES_INVALIDAS');
        }
        [$estado, $r] = $this->enviar('/api/login', ['correo' => $correo, 'password' => $password]);
        $this->comprobar('intento 6 con la contraseña CORRECTA: DEMASIADOS_INTENTOS (429)', $estado === 429 && ($r['error']['codigo'] ?? '') === 'DEMASIADOS_INTENTOS');

        $this->newLine();
        if ($this->fallos === 0) {
            $this->info('TODO CORRECTO. Revisa: php artisan bitacora:ver   y   php artisan banco:resumen');

            return self::SUCCESS;
        }
        $this->error("{$this->fallos} comprobación(es) fallaron.");

        return self::FAILURE;
    }

    /** Solicitud de tickets firmada con la llave del cliente, como lo haría la app. */
    private function solicitud(string $idCuenta, array $denominaciones): array
    {
        $nonce = $this->cripto->nonce();
        $ts = time();

        return [
            'id_cuenta' => $idCuenta,
            'denominaciones' => $denominaciones,
            'nonce' => $nonce,
            'timestamp' => $ts,
            'firma_u' => $this->firmante->firmar(Canonico::solicitud($idCuenta, $denominaciones, $nonce, $ts)),
        ];
    }

    /** @return array{0:int,1:array} [estado HTTP, cuerpo]: el éxito se descifra; el error llega en claro. */
    private function enviar(string $ruta, array $cuerpo, ?string $token = null, bool $alterar = false): array
    {
        [$sobre, $k] = $this->cripto->crearSobre(json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->rsaPubBanco);
        if ($alterar) {
            $ct = base64_decode($sobre['ct'], true);
            $ct[0] = chr(ord($ct[0]) ^ 0x01);   // cambia un solo bit del cifrado
            $sobre['ct'] = base64_encode($ct);
        }

        return $this->enviarSobre($ruta, $sobre, $k, $token);
    }

    private function enviarSobre(string $ruta, array $sobre, string $k, ?string $token): array
    {
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
