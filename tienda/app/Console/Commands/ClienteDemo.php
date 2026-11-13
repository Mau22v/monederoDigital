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
 */
class ClienteDemo extends Command
{
    protected $signature = 'cliente:demo
        {--url=http://localhost:8002 : URL del servidor Tienda}
        {--banco=http://localhost:8001 : URL del servidor Banco}';
    protected $description = 'Simula una app cliente contra la Tienda (catálogo, pedidos, estado, E1, E3 y E4)';

    private CryptoService $cripto;      // cifra sobres
    private CryptoService $firmante;    // firma como la app (llave privada del cliente)
    private string $rsaPubBanco;
    private string $ecPubBanco;
    private string $rsaPubTienda;
    private string $ecPubTienda;
    private string $urlTienda;
    private string $urlBanco;
    private int $fallos = 0;

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

        $this->newLine();
        if ($this->fallos === 0) {
            $this->info('TODO CORRECTO. Revisa: php artisan bitacora:ver   y   php artisan tienda:resumen');

            return self::SUCCESS;
        }
        $this->error("{$this->fallos} comprobación(es) fallaron.");

        return self::FAILURE;
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
        $cuerpo = ['correo' => 'tienda' . bin2hex(random_bytes(4)) . '@ejemplo.com', 'password' => 'ClaveDemo-2026', 'pub_u' => $pubU];
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

    private function enviarSobre(string $url, array $sobre, string $k): array
    {
        $resp = Http::acceptJson()->asJson()->post($url, $sobre);
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
