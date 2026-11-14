<?php

namespace App\Console\Commands;

use App\Services\Canonico;
use App\Services\CryptoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Simula a la app Android contra los servidores Banco y Tienda YA EN EJECUCIÓN: se registra en el banco para
 * obtener un Cert_U real, y con él consulta el catálogo, crea pedidos y prueba los escenarios E1 (catálogo
 * alterado), E3 (firma y certificado), E4 (mensaje alterado) y la repetición de mensajes.
 * Fase 8: compra tickets en el banco, PAGA el pedido con ellos y prueba E1 (ticket alterado), E2 (ticket reutilizado),
 * E3 (orden alterada), E4 (pago alterado), el pago repetido (idempotente) y el monto incorrecto.
 */
class ClienteDemo extends Command
{
    protected $signature = 'cliente:demo
        {--url=http://localhost:8002 : URL del servidor Tienda}
        {--banco=http://localhost:8001 : URL del servidor Banco}';
    protected $description = 'Simula una app cliente contra la Tienda y el Banco (catálogo, pedidos, pago con tickets, estado, E1, E2, E3 y E4)';

    private CryptoService $cripto;      // cifra sobres
    private CryptoService $firmante;    // firma como la app (llave privada del cliente)
    private string $rsaPubBanco;
    private string $ecPubBanco;
    private string $rsaPubTienda;
    private string $ecPubTienda;
    private string $urlTienda;
    private string $urlBanco;
    private int $fallos = 0;
    private string $ultimoCorreo = '';   // correo de la última cuenta registrada (para iniciar sesión)
    private const PASSWORD = 'ClaveDemo-2026';

    public function handle(): int
    {
        $this->urlTienda = rtrim((string) $this->option('url'), '/');
        $this->urlBanco = rtrim((string) $this->option('banco'), '/');
        $dir = base_path('../claves-publicas/');
        $this->rsaPubBanco = (string) @file_get_contents($dir . 'banco_rsa_pub.pem');
        $this->ecPubBanco = (string) @file_get_contents($dir . 'banco_ec_pub.pem');
        $this->rsaPubTienda = (string) @file_get_contents($dir . 'tienda_rsa_pub.pem');
        $this->ecPubTienda = (string) @file_get_contents($dir . 'tienda_ec_pub.pem');
        if (in_array('', [$this->rsaPubBanco, $this->ecPubBanco, $this->rsaPubTienda, $this->ecPubTienda], true)) {
            $this->error('Faltan llaves públicas en ../claves-publicas/ (banco_rsa_pub, banco_ec_pub, tienda_rsa_pub, tienda_ec_pub)');

            return self::FAILURE;
        }

        // Llave del cliente: un par EC P-256 nuevo (la app real usa el Android Keystore).
        [$this->firmante, $pubU] = $this->nuevoCliente();
        $this->cripto = new CryptoService();

        $this->info('== 1. Registro en el BANCO para obtener un Cert_U real ==');
        $cert = $this->registrar($pubU);
        $this->comprobar('el banco entregó un Cert_U con su firma válida', $cert !== null
            && $this->cripto->verificar(Canonico::cert($cert['id_cuenta'], $cert['pub_u'], $cert['expiracion']), $cert['firma_b'], $this->ecPubBanco));
        if ($cert === null) {
            $this->error('Sin Cert_U no se puede continuar. ¿Está encendido el banco (puerto 8001)?');

            return self::FAILURE;
        }
        $idA = $cert['id_cuenta'];
        $correoA = $this->ultimoCorreo;

        $this->info('== 2. Catálogo (público y firmado) ==');
        $resp = Http::acceptJson()->get($this->urlTienda . '/api/catalogo');
        $cat = $resp->json() ?? [];
        $this->comprobar('responde 200 con 12 productos', $resp->status() === 200 && count($cat['productos'] ?? []) === 12);
        $firmaOk = fn (array $c) => $this->cripto->verificar(Canonico::catalogo($c['timestamp'], $c['productos']), $c['firma_t'], $this->ecPubTienda);
        $this->comprobar('la firma de la tienda sobre el catálogo es válida', $firmaOk($cat));

        $this->info('== 3. E1 (versión catálogo): un atacante cambia un precio ==');
        $falso = $cat;
        $falso['productos'][0]['precio'] = 1;
        $this->comprobar('el catálogo con el precio cambiado NO verifica', !$firmaOk($falso));

        $this->info('== 4. Crear un pedido: Taza x1 y Libreta x2 ==');
        $items = [['id' => 1, 'cantidad' => 1], ['id' => 3, 'cantidad' => 2]];
        [$estado, $r] = $this->enviar('/api/pedidos', $this->pedido($cert, $items));
        $p = $r['pedido'] ?? [];
        $this->comprobar('responde 201', $estado === 201);
        $this->comprobar('monto 490 (370 + 2 x 60), de la cuenta correcta', ($p['monto'] ?? 0) === 490 && ($p['id_cuenta'] ?? '') === $idA);
        $this->comprobar('firma de la tienda sobre el pedido válida', $p !== [] && $this->cripto->verificar(
            Canonico::pedido($p['id_pedido'], $p['id_cuenta'], $p['monto'], $p['nonce_t'], $p['expiracion']), $p['firma_t'], $this->ecPubTienda
        ));
        $this->comprobar('vence en unos 600 s', ($p['expiracion'] ?? 0) - time() >= 590 && ($p['expiracion'] ?? 0) - time() <= 610);
        $idPedido = $p['id_pedido'] ?? '';

        $this->info('== 5. Estado del pedido ==');
        [$estado, $r] = $this->enviar("/api/pedidos/$idPedido/estado", $this->consulta($cert, $idPedido));
        $this->comprobar('estado CREADO y comprobante nulo', $estado === 200 && ($r['estado'] ?? '') === 'CREADO' && array_key_exists('comprobante', $r) && $r['comprobante'] === null);

        $this->info('== 6. E3: firma del cliente alterada ==');
        $f = $this->pedido($cert, $items);
        $bytes = base64_decode($f['firma_u']);
        $bytes[10] = chr(ord($bytes[10]) ^ 0x01);
        $f['firma_u'] = base64_encode($bytes);
        [$estado, $r] = $this->enviar('/api/pedidos', $f);
        $this->comprobar('rechazado con FIRMA_INVALIDA (403)', $estado === 403 && ($r['error']['codigo'] ?? '') === 'FIRMA_INVALIDA');

        $this->info('== 7. E3: se firma un pedido y se envía otro ==');
        $f = $this->pedido($cert, [['id' => 9, 'cantidad' => 1]]);
        $f['items'] = [['id' => 12, 'cantidad' => 10]];
        [$estado, $r] = $this->enviar('/api/pedidos', $f);
        $this->comprobar('rechazado con FIRMA_INVALIDA (403)', $estado === 403 && ($r['error']['codigo'] ?? '') === 'FIRMA_INVALIDA');

        $this->info('== 8. E3: Cert_U forjado (llave del atacante dentro del certificado de la víctima) ==');
        [$atacante, $pubAtacante] = $this->nuevoCliente();
        $forjado = ['pub_u' => $pubAtacante] + $cert;
        $f = $this->pedido($forjado, $items, $atacante);
        [$estado, $r] = $this->enviar('/api/pedidos', $f);
        $this->comprobar('rechazado con CERTIFICADO_INVALIDO (403)', $estado === 403 && ($r['error']['codigo'] ?? '') === 'CERTIFICADO_INVALIDO');

        $this->info('== 9. E3: certificado válido pero firma de otra llave ==');
        [$estado, $r] = $this->enviar('/api/pedidos', $this->pedido($cert, $items, $atacante));
        $this->comprobar('rechazado con FIRMA_INVALIDA (403)', $estado === 403 && ($r['error']['codigo'] ?? '') === 'FIRMA_INVALIDA');

        $this->info('== 10. Repetición del mismo mensaje cifrado ==');
        [$sobre, $k] = $this->cripto->crearSobre(json_encode($this->pedido($cert, [['id' => 9, 'cantidad' => 1]])), $this->rsaPubTienda);
        [$e1] = $this->enviarSobre($this->urlTienda . '/api/pedidos', $sobre, $k);
        [$e2, $r] = $this->enviarSobre($this->urlTienda . '/api/pedidos', $sobre, $k);
        $this->comprobar('la primera vez: 201', $e1 === 201);
        $this->comprobar('la repetición: NONCE_REPETIDO (409)', $e2 === 409 && ($r['error']['codigo'] ?? '') === 'NONCE_REPETIDO');

        $this->info('== 11. Producto inexistente ==');
        [$estado, $r] = $this->enviar('/api/pedidos', $this->pedido($cert, [['id' => 999, 'cantidad' => 1]]));
        $this->comprobar('rechazado con FORMATO_INVALIDO (400)', $estado === 400 && ($r['error']['codigo'] ?? '') === 'FORMATO_INVALIDO');

        $this->info('== 12. E4: mensaje cifrado modificado ==');
        [$estado, $r] = $this->enviar('/api/pedidos', $this->pedido($cert, $items), true);
        $this->comprobar('rechazado con MENSAJE_CORRUPTO (400)', $estado === 400 && ($r['error']['codigo'] ?? '') === 'MENSAJE_CORRUPTO');

        $this->info('== 13. El pedido de A no es visible para otra cuenta (B) ==');
        [$firmanteB, $pubB] = $this->nuevoCliente();
        $certB = $this->registrar($pubB);
        $this->comprobar('B obtuvo su propio Cert_U', $certB !== null);
        if ($certB !== null) {
            [$estado, $r] = $this->enviar("/api/pedidos/$idPedido/estado", $this->consulta($certB, $idPedido, $firmanteB));
            $this->comprobar('B recibe PEDIDO_NO_ENCONTRADO (404)', $estado === 404 && ($r['error']['codigo'] ?? '') === 'PEDIDO_NO_ENCONTRADO');
        }

        // ---------------- Fase 8: pago con tickets ----------------
        $this->info('== 14. Comprar tickets en el BANCO (login + solicitud firmada): 300+100+50+20+20 = 490 ==');
        $token = $this->login($correoA);
        $this->comprobar('login en el banco entregó un token', $token !== null);
        $tickets = $token === null ? [] : $this->comprarTickets($cert, $token, [300 => 1, 100 => 1, 50 => 1, 20 => 2]);
        $this->comprobar('el banco entregó 5 tickets, todos con su firma válida y de la cuenta A', count($tickets) === 5 && $this->ticketsAutenticos($tickets, $idA));
        if (count($tickets) !== 5) {
            $this->error('Sin tickets no se puede continuar con el pago.');

            return self::FAILURE;
        }

        $this->info('== 15. E1: un ticket de $20 se convierte en uno de $500 (denominación alterada) ==');
        $alterados = $tickets;
        $alterados[3]['denominacion'] = 500;
        [$estado, $r] = $this->enviar("/api/pedidos/$idPedido/pago", $this->pago($cert, $idPedido, $alterados));
        $this->comprobar('rechazado con TICKET_INVALIDO (403)', $estado === 403 && ($r['error']['codigo'] ?? '') === 'TICKET_INVALIDO');
        [, $r] = $this->enviar("/api/pedidos/$idPedido/estado", $this->consulta($cert, $idPedido));
        $this->comprobar('el pedido sigue CREADO (no se cobró nada)', ($r['estado'] ?? '') === 'CREADO');

        $this->info('== 16. E3: la firma de la orden de pago se altera ==');
        $f = $this->pago($cert, $idPedido, $tickets);
        $bytes = base64_decode($f['firma_u']);
        $bytes[10] = chr(ord($bytes[10]) ^ 0x01);
        $f['firma_u'] = base64_encode($bytes);
        [$estado, $r] = $this->enviar("/api/pedidos/$idPedido/pago", $f);
        $this->comprobar('rechazado con FIRMA_INVALIDA (403)', $estado === 403 && ($r['error']['codigo'] ?? '') === 'FIRMA_INVALIDA');

        $this->info('== 17. E4: el mensaje cifrado del pago se modifica ==');
        [$estado, $r] = $this->enviar("/api/pedidos/$idPedido/pago", $this->pago($cert, $idPedido, $tickets), true);
        $this->comprobar('rechazado con MENSAJE_CORRUPTO (400)', $estado === 400 && ($r['error']['codigo'] ?? '') === 'MENSAJE_CORRUPTO');

        $this->info('== 18. PAGO correcto con los 5 tickets ==');
        $pagoOk = $this->pago($cert, $idPedido, $tickets);
        [$sobre, $k] = $this->cripto->crearSobre(json_encode($pagoOk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->rsaPubTienda);
        [$estado, $r] = $this->enviarSobre($this->urlTienda . "/api/pedidos/$idPedido/pago", $sobre, $k);
        $c = $r['comprobante'] ?? [];
        $rc = $r['recibo_canje'] ?? [];
        $this->comprobar('responde 200 con comprobante PAGADO por 490', $estado === 200 && ($c['estado'] ?? '') === 'PAGADO' && ($c['monto'] ?? 0) === 490 && ($c['id_cuenta'] ?? '') === $idA);
        $this->comprobar('la firma de la tienda sobre el comprobante es válida', $c !== [] && $this->cripto->verificar(
            Canonico::comprobante($c['id_pedido'], $c['id_cuenta'], $c['monto'], $c['estado'], $c['timestamp']), $c['firma_t'], $this->ecPubTienda
        ));
        $this->comprobar('la firma del BANCO sobre el recibo de canje es válida', $rc !== [] && $this->cripto->verificar(
            Canonico::reciboCanje($rc['id_pedido'], $rc['monto'], $rc['ids'], $rc['timestamp']), $rc['firma_b'], $this->ecPubBanco
        ));
        [, $e] = $this->enviar("/api/pedidos/$idPedido/estado", $this->consulta($cert, $idPedido));
        $this->comprobar('el estado ahora es PAGADO y trae el mismo comprobante', ($e['estado'] ?? '') === 'PAGADO' && ($e['comprobante'] ?? null) === $c);

        $this->info('== 19. Repetir el pago (por ejemplo, tras cortarse la red): misma respuesta, sin cobrar dos veces ==');
        [$estado, $r2] = $this->enviar("/api/pedidos/$idPedido/pago", $this->pago($cert, $idPedido, $tickets));
        $this->comprobar('responde 200 con el MISMO comprobante', $estado === 200 && ($r2['comprobante'] ?? null) === $c);

        $this->info('== 20. Repetir el mismo mensaje cifrado del pago ==');
        [$estado, $r] = $this->enviarSobre($this->urlTienda . "/api/pedidos/$idPedido/pago", $sobre, $k);
        $this->comprobar('rechazado con NONCE_REPETIDO (409)', $estado === 409 && ($r['error']['codigo'] ?? '') === 'NONCE_REPETIDO');

        $this->info('== 21. E2: los mismos tickets (ya gastados) se usan para pagar OTRO pedido ==');
        [, $r] = $this->enviar('/api/pedidos', $this->pedido($cert, $items));
        $idDos = $r['pedido']['id_pedido'] ?? '';
        [$estado, $r] = $this->enviar("/api/pedidos/$idDos/pago", $this->pago($cert, $idDos, $tickets));
        $this->comprobar('rechazado con TICKET_GASTADO (409)', $estado === 409 && ($r['error']['codigo'] ?? '') === 'TICKET_GASTADO');
        [, $r] = $this->enviar("/api/pedidos/$idDos/estado", $this->consulta($cert, $idDos));
        $this->comprobar('el segundo pedido sigue CREADO', ($r['estado'] ?? '') === 'CREADO');

        $this->info('== 22. Monto incorrecto: tickets por $500 para un pedido de $490 (no hay cambio) ==');
        $quinientos = $this->comprarTickets($cert, $token, [500 => 1]);
        [$estado, $r] = $this->enviar("/api/pedidos/$idDos/pago", $this->pago($cert, $idDos, $quinientos));
        $this->comprobar('rechazado con MONTO_INCORRECTO (422)', $estado === 422 && ($r['error']['codigo'] ?? '') === 'MONTO_INCORRECTO');

        $this->info('== 23. Pedido ya pagado: no se puede pagar con otros tickets ==');
        $otros = $this->comprarTickets($cert, $token, [300 => 1, 100 => 1, 50 => 1, 20 => 2]);
        [$estado, $r] = $this->enviar("/api/pedidos/$idPedido/pago", $this->pago($cert, $idPedido, $otros));
        $this->comprobar('rechazado con PEDIDO_DUPLICADO (409)', $estado === 409 && ($r['error']['codigo'] ?? '') === 'PEDIDO_DUPLICADO');

        $this->info('== 24. Un ticket que el banco nunca emitió (fabricado por el cliente con su propia llave) ==');
        $falso = $this->ticketFabricado($idA);
        [$estado, $r] = $this->enviar("/api/pedidos/$idDos/pago", $this->pago($cert, $idDos, [$falso, ...array_slice($otros, 1)]));
        $this->comprobar('rechazado con TICKET_INVALIDO (403)', $estado === 403 && ($r['error']['codigo'] ?? '') === 'TICKET_INVALIDO');

        $this->newLine();
        if ($this->fallos === 0) {
            $this->info('TODO CORRECTO. Revisa: php artisan bitacora:ver   y   php artisan tienda:resumen   (y en el banco: php artisan banco:resumen)');

            return self::SUCCESS;
        }
        $this->error("{$this->fallos} comprobación(es) fallaron.");

        return self::FAILURE;
    }

    /** Inicia sesión en el banco y devuelve el token JWT (o null). */
    private function login(string $correo): ?string
    {
        [$sobre, $k] = $this->cripto->crearSobre(json_encode(['correo' => $correo, 'password' => self::PASSWORD]), $this->rsaPubBanco);
        [$estado, $r] = $this->enviarSobre($this->urlBanco . '/api/login', $sobre, $k);

        return $estado === 200 ? ($r['token'] ?? null) : null;
    }

    /**
     * Compra tickets en el banco con una solicitud firmada. $denominaciones: [denominación => cantidad].
     * @return array<int,array> los tickets recibidos ([] si falla)
     */
    private function comprarTickets(array $cert, string $token, array $denominaciones): array
    {
        $nonce = $this->cripto->nonce();
        $ts = time();
        $cuerpo = [
            'id_cuenta' => $cert['id_cuenta'], 'denominaciones' => $denominaciones, 'nonce' => $nonce, 'timestamp' => $ts,
            'firma_u' => $this->firmante->firmar(Canonico::solicitud($cert['id_cuenta'], $denominaciones, $nonce, $ts)),
        ];
        [$sobre, $k] = $this->cripto->crearSobre(json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->rsaPubBanco);
        [$estado, $r] = $this->enviarSobre($this->urlBanco . '/api/tickets/solicitar', $sobre, $k, $token);

        return $estado === 201 ? ($r['tickets'] ?? []) : [];
    }

    private function ticketsAutenticos(array $tickets, string $idCuenta): bool
    {
        foreach ($tickets as $t) {
            $cadena = Canonico::ticket($t['id'], $t['denominacion'], $t['id_cuenta'], $t['emision'], $t['expiracion']);
            if ($t['id_cuenta'] !== $idCuenta || !$this->cripto->verificar($cadena, $t['firma_b'], $this->ecPubBanco)) {
                return false;
            }
        }

        return true;
    }

    /** Un ticket de $300 con formato correcto pero firmado por el propio cliente (no por el banco). */
    private function ticketFabricado(string $idCuenta): array
    {
        $id = sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));
        $emision = time();
        $expiracion = $emision + 2592000;

        return [
            'id' => $id, 'denominacion' => 300, 'id_cuenta' => $idCuenta, 'emision' => $emision, 'expiracion' => $expiracion,
            'firma_b' => $this->firmante->firmar(Canonico::ticket($id, 300, $idCuenta, $emision, $expiracion)),
        ];
    }

    /** Orden de pago firmada como la app. $firmante permite firmar con otra llave (ataque). */
    private function pago(array $cert, string $idPedido, array $tickets, ?CryptoService $firmante = null): array
    {
        $nonce = $this->cripto->nonce();
        $ts = time();
        $monto = array_sum(array_column($tickets, 'denominacion'));

        return [
            'cert_u' => $cert,
            'orden' => ['id_pedido' => $idPedido, 'monto' => $monto, 'tickets' => $tickets, 'nonce' => $nonce, 'timestamp' => $ts],
            'firma_u' => ($firmante ?? $this->firmante)->firmar(Canonico::orden($idPedido, $monto, array_column($tickets, 'id'), $nonce, $ts)),
        ];
    }

    /** @return array{0:CryptoService,1:string} [firmante, pub_u Base64] */
    private function nuevoCliente(): array
    {
        $par = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($par, $privada);

        return [new CryptoService($privada), preg_replace('/-----[A-Z ]+-----|\s+/', '', openssl_pkey_get_details($par)['key'])];
    }

    /** Registra una cuenta nueva en el banco y devuelve su Cert_U (o null si falla). */
    private function registrar(string $pubU): ?array
    {
        $this->ultimoCorreo = 'tienda' . bin2hex(random_bytes(4)) . '@ejemplo.com';
        $cuerpo = ['correo' => $this->ultimoCorreo, 'password' => self::PASSWORD, 'pub_u' => $pubU];
        [$sobre, $k] = $this->cripto->crearSobre(json_encode($cuerpo), $this->rsaPubBanco);
        [$estado, $r] = $this->enviarSobre($this->urlBanco . '/api/registro', $sobre, $k);

        return $estado === 201 ? ($r['cert_u'] ?? null) : null;
    }

    /** Pedido firmado como la app. $firmante permite firmar con otra llave (ataque). */
    private function pedido(array $cert, array $items, ?CryptoService $firmante = null): array
    {
        $nonce = $this->cripto->nonce();
        $ts = time();

        return [
            'cert_u' => $cert, 'items' => $items, 'nonce' => $nonce, 'timestamp' => $ts,
            'firma_u' => ($firmante ?? $this->firmante)->firmar(Canonico::crearPedido($cert['id_cuenta'], $items, $nonce, $ts)),
        ];
    }

    private function consulta(array $cert, string $idPedido, ?CryptoService $firmante = null): array
    {
        $nonce = $this->cripto->nonce();
        $ts = time();

        return [
            'cert_u' => $cert, 'nonce' => $nonce, 'timestamp' => $ts,
            'firma_u' => ($firmante ?? $this->firmante)->firmar(Canonico::consultaPedido($idPedido, $cert['id_cuenta'], $nonce, $ts)),
        ];
    }

    /** @return array{0:int,1:array} [estado HTTP, cuerpo]: el éxito se descifra; el error llega en claro. */
    private function enviar(string $ruta, array $cuerpo, bool $alterar = false): array
    {
        [$sobre, $k] = $this->cripto->crearSobre(json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->rsaPubTienda);
        if ($alterar) {
            $ct = base64_decode($sobre['ct'], true);
            $ct[0] = chr(ord($ct[0]) ^ 0x01);   // cambia un solo bit del cifrado
            $sobre['ct'] = base64_encode($ct);
        }

        return $this->enviarSobre($this->urlTienda . $ruta, $sobre, $k);
    }

    private function enviarSobre(string $url, array $sobre, string $k, ?string $token = null): array
    {
        $http = $token === null ? Http::acceptJson() : Http::withToken($token)->acceptJson();
        $resp = $http->asJson()->post($url, $sobre);
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
