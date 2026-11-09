<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Canonico;
use App\Services\CryptoService;
use App\Services\MensajeCorruptoException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas de la Fase 3. No usan los archivos de storage/app/keys: generan llaves temporales,
 * así que pasan en cualquier equipo.
 */
class CryptoServiceTest extends TestCase
{
    private string $ecPriv;
    private string $ecPub;
    private string $rsaPriv;
    private string $rsaPub;
    private CryptoService $cripto;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->ecPriv, $this->ecPub] = $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        [$this->rsaPriv, $this->rsaPub] = $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->cripto = new CryptoService($this->ecPriv, $this->rsaPriv);
    }

    /** @return array{0:string,1:string} [privada PEM, pública PEM] */
    private function nuevaLlave(array $opciones): array
    {
        $recurso = openssl_pkey_new($opciones);
        openssl_pkey_export($recurso, $privada);

        return [$privada, openssl_pkey_get_details($recurso)['key']];
    }

    /** Cambia un bit del dato binario en la posición indicada. */
    private function alterar(string $binario, int $posicion = 0): string
    {
        $binario[$posicion] = chr(ord($binario[$posicion]) ^ 0x01);

        return $binario;
    }

    // ---------------- Hash ----------------

    public function test_sha256_coincide_con_el_vector_conocido(): void
    {
        $this->assertSame(
            'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
            $this->cripto->sha256Hex('abc')
        );
    }

    public function test_el_nonce_tiene_32_hex_y_no_se_repite(): void
    {
        $a = $this->cripto->nonce();
        $b = $this->cripto->nonce();
        $this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', $a));
        $this->assertNotSame($a, $b);
    }

    // ---------------- Firma digital ----------------

    public function test_una_firma_valida_verifica(): void
    {
        $mensaje = 'ticket|v1|abc|500|cuenta|1762500000|1765092000';
        $firma = $this->cripto->firmar($mensaje);
        $this->assertTrue($this->cripto->verificar($mensaje, $firma, $this->ecPub));
    }

    public function test_la_firma_tiene_formato_der(): void
    {
        $der = base64_decode($this->cripto->firmar('x'), true);
        $this->assertSame(0x30, ord($der[0]));   // las firmas DER (ASN.1) empiezan con 0x30
    }

    public function test_un_mensaje_modificado_no_verifica(): void   // escenario E1
    {
        $firma = $this->cripto->firmar('ticket|v1|abc|50|cuenta|1|2');
        $this->assertFalse($this->cripto->verificar('ticket|v1|abc|500|cuenta|1|2', $firma, $this->ecPub));
    }

    public function test_una_firma_alterada_no_verifica(): void   // escenario E3
    {
        $mensaje = 'orden|v1|p|370|a,b|n|1';
        $alterada = base64_encode($this->alterar(base64_decode($this->cripto->firmar($mensaje)), 10));
        $this->assertFalse($this->cripto->verificar($mensaje, $alterada, $this->ecPub));
    }

    public function test_una_firma_de_otra_llave_no_verifica(): void
    {
        [, $otraPub] = $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $firma = $this->cripto->firmar('hola');
        $this->assertFalse($this->cripto->verificar('hola', $firma, $otraPub));
    }

    public function test_basura_como_firma_o_como_llave_devuelve_false(): void
    {
        $this->assertFalse($this->cripto->verificar('hola', '%%%no-es-base64%%%', $this->ecPub));
        $this->assertFalse($this->cripto->verificar('hola', '', $this->ecPub));
        $this->assertFalse($this->cripto->verificar('hola', $this->cripto->firmar('hola'), 'no es una llave'));
    }

    public function test_una_llave_publica_rsa_no_se_acepta_para_firmas(): void
    {
        $this->assertFalse($this->cripto->esLlaveEcP256($this->rsaPub));
        $this->assertTrue($this->cripto->esLlaveEcP256($this->ecPub));
        $this->assertFalse($this->cripto->verificar('hola', $this->cripto->firmar('hola'), $this->rsaPub));
    }

    public function test_pem_desde_base64_reconstruye_la_llave_publica(): void
    {
        // Lo que manda Android: la llave pública en X.509 DER codificada en Base64
        $b64 = preg_replace('/-----[A-Z ]+-----|\s/', '', $this->ecPub);
        $pem = $this->cripto->pemDesdeBase64($b64);
        $firma = $this->cripto->firmar('hola');
        $this->assertTrue($this->cripto->verificar('hola', $firma, $pem));
        $this->assertTrue($this->cripto->esLlaveEcP256($pem));
    }

    public function test_pem_desde_base64_rechaza_texto_invalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cripto->pemDesdeBase64('@@@');
    }

    // ---------------- Sobre híbrido ----------------

    public function test_el_sobre_ida_y_vuelta_funciona(): void
    {
        $json = '{"correo":"a@b.com","password":"secreta","acentos":"añoñ ✓"}';

        // Quien envía
        [$sobre, $k] = $this->cripto->crearSobre($json, $this->rsaPub);
        $this->assertSame(['k', 'iv', 'ct'], array_keys($sobre));

        // El servidor
        [$plano, $kServidor] = $this->cripto->abrirSobre($sobre);
        $this->assertSame($json, $plano);
        $this->assertSame($k, $kServidor);

        // Respuesta con la misma K y un IV nuevo
        $respuesta = $this->cripto->cerrarRespuesta('{"ok":true}', $kServidor);
        $this->assertSame(['iv', 'ct'], array_keys($respuesta));
        $this->assertNotSame($sobre['iv'], $respuesta['iv']);
        $this->assertSame('{"ok":true}', $this->cripto->abrirRespuesta($respuesta, $k));
    }

    public function test_el_texto_cifrado_no_contiene_el_mensaje_original(): void
    {
        [$sobre] = $this->cripto->crearSobre('password-super-secreta', $this->rsaPub);
        $this->assertFalse(str_contains(base64_decode($sobre['ct']), 'password'));
    }

    public function test_dos_sobres_del_mismo_mensaje_son_distintos(): void
    {
        [$a] = $this->cripto->crearSobre('igual', $this->rsaPub);
        [$b] = $this->cripto->crearSobre('igual', $this->rsaPub);
        $this->assertNotSame($a['ct'], $b['ct']);
        $this->assertNotSame($a['k'], $b['k']);
    }

    public function test_un_mensaje_cifrado_modificado_se_rechaza(): void   // escenario E4
    {
        [$sobre] = $this->cripto->crearSobre('{"monto":370}', $this->rsaPub);
        $sobre['ct'] = base64_encode($this->alterar(base64_decode($sobre['ct']), 0));

        $this->expectException(MensajeCorruptoException::class);
        $this->cripto->abrirSobre($sobre);
    }

    public function test_una_etiqueta_gcm_modificada_se_rechaza(): void   // escenario E4
    {
        [$sobre] = $this->cripto->crearSobre('{"monto":370}', $this->rsaPub);
        $ct = base64_decode($sobre['ct']);
        $sobre['ct'] = base64_encode($this->alterar($ct, strlen($ct) - 1));   // último byte = etiqueta

        $this->expectException(MensajeCorruptoException::class);
        $this->cripto->abrirSobre($sobre);
    }

    public function test_un_iv_modificado_se_rechaza(): void
    {
        [$sobre] = $this->cripto->crearSobre('{"monto":370}', $this->rsaPub);
        $sobre['iv'] = base64_encode($this->alterar(base64_decode($sobre['iv'])));

        $this->expectException(MensajeCorruptoException::class);
        $this->cripto->abrirSobre($sobre);
    }

    public function test_una_llave_k_modificada_se_rechaza(): void
    {
        [$sobre] = $this->cripto->crearSobre('{"monto":370}', $this->rsaPub);
        $sobre['k'] = base64_encode($this->alterar(base64_decode($sobre['k']), 5));

        $this->expectException(MensajeCorruptoException::class);
        $this->cripto->abrirSobre($sobre);
    }

    public function test_un_sobre_cifrado_para_otra_llave_se_rechaza(): void
    {
        [, $otraRsaPub] = $this->nuevaLlave(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        [$sobre] = $this->cripto->crearSobre('hola', $otraRsaPub);

        $this->expectException(MensajeCorruptoException::class);
        $this->cripto->abrirSobre($sobre);
    }

    public function test_sobres_incompletos_o_con_basura_se_rechazan(): void
    {
        foreach ([[], ['k' => 'x'], ['k' => 1, 'iv' => 2, 'ct' => 3], ['k' => '@@', 'iv' => '@@', 'ct' => '@@']] as $sobre) {
            try {
                $this->cripto->abrirSobre($sobre);
                $this->fail('Debió lanzar MensajeCorruptoException');
            } catch (MensajeCorruptoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_una_respuesta_modificada_se_rechaza_en_el_cliente(): void
    {
        [$sobre, $k] = $this->cripto->crearSobre('hola', $this->rsaPub);
        [, $kServidor] = $this->cripto->abrirSobre($sobre);
        $respuesta = $this->cripto->cerrarRespuesta('{"saldo":8700}', $kServidor);
        $respuesta['ct'] = base64_encode($this->alterar(base64_decode($respuesta['ct'])));

        $this->expectException(MensajeCorruptoException::class);
        $this->cripto->abrirRespuesta($respuesta, $k);
    }

    // ---------------- Cadenas canónicas ----------------

    public function test_el_ticket_coincide_con_el_ejemplo_del_contrato(): void
    {
        $this->assertSame(
            'ticket|v1|3f2b8c1e-5d4a-4e0b-9c7a-1b2c3d4e5f60|500|7a1d9e22-0c3b-4f8a-8b11-aa22bb33cc44|1762500000|1765092000',
            Canonico::ticket('3f2b8c1e-5d4a-4e0b-9c7a-1b2c3d4e5f60', 500, '7a1d9e22-0c3b-4f8a-8b11-aa22bb33cc44', 1762500000, 1765092000)
        );
    }

    public function test_la_solicitud_ordena_las_denominaciones_de_mayor_a_menor(): void
    {
        $this->assertSame(
            'solicitud|v1|cuenta|500:2,300:1,20:3|nonce|10',
            Canonico::solicitud('cuenta', [20 => 3, 500 => 2, 100 => 0, 300 => 1], 'nonce', 10)
        );
    }

    public function test_las_listas_de_ids_se_ordenan_de_forma_ascendente(): void
    {
        $this->assertSame(
            'orden|v1|ped|370|aaa,bbb,ccc|nonce|10',
            Canonico::orden('ped', 370, ['ccc', 'aaa', 'bbb'], 'nonce', 10)
        );
    }

    public function test_el_catalogo_y_los_items_se_ordenan_por_id(): void
    {
        $this->assertSame(
            'catalogo|v1|10|1:Taza:370;2:Libro:150',
            Canonico::catalogo(10, [['id' => 2, 'nombre' => 'Libro', 'precio' => 150], ['id' => 1, 'nombre' => 'Taza', 'precio' => 370]])
        );
        $this->assertSame(
            'crear_pedido|v1|cuenta|1:2,3:1|nonce|10',
            Canonico::crearPedido('cuenta', [['id' => 3, 'cantidad' => 1], ['id' => 1, 'cantidad' => 2]], 'nonce', 10)
        );
    }

    public function test_un_campo_con_el_separador_se_rechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Canonico::pedido('abc|def', 'cuenta', 370, 'nonce', 10);
    }

    public function test_un_nombre_de_producto_con_caracteres_prohibidos_se_rechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Canonico::catalogo(10, [['id' => 1, 'nombre' => 'Taza: azul', 'precio' => 370]]);
    }

    public function test_firmar_la_cadena_canonica_de_un_ticket_funciona_de_punta_a_punta(): void
    {
        $cadena = Canonico::ticket('3f2b8c1e-5d4a-4e0b-9c7a-1b2c3d4e5f60', 500, 'cuenta', 1, 2);
        $firma = $this->cripto->firmar($cadena);
        $this->assertTrue($this->cripto->verificar($cadena, $firma, $this->ecPub));

        // Un ticket alterado (otra denominación) produce otra cadena y la firma ya no sirve
        $alterada = Canonico::ticket('3f2b8c1e-5d4a-4e0b-9c7a-1b2c3d4e5f60', 50, 'cuenta', 1, 2);
        $this->assertFalse($this->cripto->verificar($alterada, $firma, $this->ecPub));
    }
}
