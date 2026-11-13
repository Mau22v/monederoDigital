<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Nonce;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Producto;
use App\Services\Canonico;
use App\Services\CreadorPedidos;
use App\Services\CryptoService;
use RuntimeException;
use Tests\TiendaTestCase;

/**
 * Fase 7: POST /api/pedidos. Cubre la creación correcta, la autenticación con Cert_U + firma_u (E3),
 * la repetición de mensajes, E4, el formato, las reglas de negocio y el orden de verificación del contrato.
 */
class PedidosTest extends TiendaTestCase
{
    // ---------------- Creación correcta ----------------

    public function test_un_pedido_valido_se_crea_y_la_respuesta_viaja_cifrada(): void
    {
        [$r, $k] = $this->enviar('/api/pedidos', $this->pedido($this->itemsBasicos()));

        $r->assertStatus(201)->assertJsonStructure(['iv', 'ct'])->assertJsonMissingPath('pedido');
        $p = $this->abrir($r, $k)['pedido'];
        $this->assertSame(['id_pedido', 'id_cuenta', 'monto', 'nonce_t', 'expiracion', 'firma_t'], array_keys($p));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $p['id_pedido']);
        $this->assertSame($this->idA, $p['id_cuenta']);
        $this->assertSame(490, $p['monto']);                       // 370 + 2 x 60
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $p['nonce_t']);
        $this->assertSame(self::T0 + 600, $p['expiracion']);
    }

    public function test_la_firma_de_la_tienda_sobre_el_pedido_verifica(): void
    {
        [$r, $k] = $this->enviar('/api/pedidos', $this->pedido($this->itemsBasicos()));
        $p = $this->abrir($r, $k)['pedido'];

        $cadena = Canonico::pedido($p['id_pedido'], $p['id_cuenta'], $p['monto'], $p['nonce_t'], $p['expiracion']);
        $this->assertTrue($this->cliente->verificar($cadena, $p['firma_t'], $this->pem('tienda_ec')));

        $otra = Canonico::pedido($p['id_pedido'], $p['id_cuenta'], 1, $p['nonce_t'], $p['expiracion']);   // monto alterado
        $this->assertFalse($this->cliente->verificar($otra, $p['firma_t'], $this->pem('tienda_ec')));
    }

    public function test_el_pedido_y_sus_renglones_quedan_guardados_con_el_precio_del_momento(): void
    {
        $id = $this->pedidoDeA();

        $this->assertSame(1, Pedido::count());
        $pedido = Pedido::find($id);
        $this->assertSame('CREADO', $pedido->estado);
        $this->assertSame($this->idA, $pedido->id_cuenta);
        $this->assertSame(490, $pedido->monto);
        $this->assertSame(self::T0, $pedido->creado_en);
        $this->assertSame(2, PedidoItem::where('id_pedido', $id)->count());
        $taza = PedidoItem::where('id_pedido', $id)->where('id_producto', 1)->first();
        $this->assertSame('Taza de cerámica', $taza->nombre);
        $this->assertSame(370, $taza->precio_unitario);
        $this->assertSame(1, $taza->cantidad);
    }

    public function test_el_monto_se_calcula_con_los_precios_del_servidor_y_un_cambio_posterior_no_altera_pedidos_viejos(): void
    {
        $primero = $this->pedidoDeA();
        Producto::find(1)->update(['precio' => 400]);

        [$r, $k] = $this->enviar('/api/pedidos', $this->pedido([['id' => 1, 'cantidad' => 1]]));
        $this->assertSame(400, $this->abrir($r, $k)['pedido']['monto']);
        $this->assertSame(490, Pedido::find($primero)->monto);
        $this->assertSame(370, PedidoItem::where('id_pedido', $primero)->where('id_producto', 1)->first()->precio_unitario);
    }

    public function test_dos_clientes_pueden_pedir_a_la_vez_y_cada_pedido_es_de_su_dueno(): void
    {
        [$ra, $ka] = $this->enviar('/api/pedidos', $this->pedido($this->itemsBasicos()));
        [$rb, $kb] = $this->enviar('/api/pedidos', $this->pedido([['id' => 9, 'cantidad' => 3]], [], $this->certB, $this->firmanteB));

        $this->assertSame($this->idA, $this->abrir($ra, $ka)['pedido']['id_cuenta']);
        $b = $this->abrir($rb, $kb)['pedido'];
        $this->assertSame($this->idB, $b['id_cuenta']);
        $this->assertSame(60, $b['monto']);
        $this->assertSame(2, Pedido::count());
    }

    public function test_el_orden_de_los_renglones_enviados_no_cambia_la_firma(): void
    {
        $items = [['id' => 3, 'cantidad' => 2], ['id' => 1, 'cantidad' => 1]];   // desordenados: la cadena canónica los ordena

        [$r] = $this->enviar('/api/pedidos', $this->pedido($items));

        $r->assertStatus(201);
    }

    // ---------------- E3: firma y certificado ----------------

    public function test_e3_una_firma_alterada_se_rechaza_sin_efectos(): void
    {
        $f = $this->pedido($this->itemsBasicos());
        $bytes = base64_decode($f['firma_u']);
        $bytes[10] = chr(ord($bytes[10]) ^ 0x01);
        $f['firma_u'] = base64_encode($bytes);

        $this->crearPedido($f)->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
        $this->assertSinEfectos();
    }

    public function test_e3_se_firma_un_pedido_y_se_envia_otro(): void
    {
        $f = $this->pedido([['id' => 9, 'cantidad' => 1]]);
        $f['items'] = [['id' => 4, 'cantidad' => 10]];

        $this->crearPedido($f)->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
        $this->assertSinEfectos();
    }

    public function test_e3_firma_hecha_con_otra_llave_o_con_la_llave_de_otro_cliente(): void
    {
        $this->crearPedido($this->pedido($this->itemsBasicos(), [], null, $this->atacante))
            ->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
        // El Cert_U es de A, pero firma B con su propia llave (que es válida, aunque no es la de ese certificado).
        $this->crearPedido($this->pedido($this->itemsBasicos(), [], $this->certA, $this->firmanteB))
            ->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
        $this->assertSinEfectos();
    }

    public function test_e3_una_firma_de_otro_tipo_de_mensaje_no_sirve(): void
    {
        $f = $this->pedido($this->itemsBasicos());
        $f['firma_u'] = $this->firmanteA->firmar(Canonico::consultaPedido($this->idA, $this->idA, $f['nonce'], $f['timestamp']));

        $this->crearPedido($f)->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
    }

    public function test_certificado_con_la_llave_publica_cambiada_por_la_del_atacante(): void
    {
        // El atacante pone SU llave en el certificado de A y firma con ella; la firma del banco ya no corresponde.
        $cert = $this->certA;
        $cert['pub_u'] = $this->pubBase64('atacante');
        $f = $this->pedido($this->itemsBasicos(), [], $cert, $this->atacante);

        $this->crearPedido($f)->assertStatus(403)->assertJsonPath('error.codigo', 'CERTIFICADO_INVALIDO');
        $this->assertSinEfectos();
    }

    public function test_certificado_fabricado_y_firmado_por_el_atacante(): void
    {
        $cert = $this->certificado($this->idA, $this->pubBase64('atacante'), null, $this->atacante);

        $this->crearPedido($this->pedido($this->itemsBasicos(), [], $cert, $this->atacante))
            ->assertStatus(403)->assertJsonPath('error.codigo', 'CERTIFICADO_INVALIDO');
    }

    public function test_certificado_con_otro_id_de_cuenta_o_otra_expiracion(): void
    {
        $otroId = $this->certA; $otroId['id_cuenta'] = $this->idB;
        $this->crearPedido($this->pedido($this->itemsBasicos(), [], $otroId))->assertJsonPath('error.codigo', 'CERTIFICADO_INVALIDO');

        $masVida = $this->certA; $masVida['expiracion'] += 1000;
        $this->crearPedido($this->pedido($this->itemsBasicos(), [], $masVida))->assertJsonPath('error.codigo', 'CERTIFICADO_INVALIDO');
        $this->assertSinEfectos();
    }

    public function test_un_certificado_vence_exactamente_en_su_expiracion(): void
    {
        $venceYa = $this->certificado($this->idA, $this->pubBase64('a'), self::T0);
        $this->crearPedido($this->pedido($this->itemsBasicos(), [], $venceYa))
            ->assertStatus(403)->assertJsonPath('error.codigo', 'CERTIFICADO_INVALIDO');

        $venceEnUnSegundo = $this->certificado($this->idA, $this->pubBase64('a'), self::T0 + 1);
        $this->crearPedido($this->pedido($this->itemsBasicos(), [], $venceEnUnSegundo))->assertStatus(201);
    }

    // ---------------- Repetición ----------------

    public function test_el_mismo_mensaje_cifrado_enviado_dos_veces_crea_un_solo_pedido(): void
    {
        [$sobre] = $this->cliente->crearSobre(json_encode($this->pedido($this->itemsBasicos())), $this->pem('tienda_rsa'));

        $this->postJson('/api/pedidos', $sobre)->assertStatus(201);
        $this->postJson('/api/pedidos', $sobre)->assertStatus(409)->assertJsonPath('error.codigo', 'NONCE_REPETIDO');
        $this->assertSame(1, Pedido::count());
    }

    public function test_el_mismo_nonce_dentro_de_un_mensaje_nuevo_tambien_se_rechaza(): void
    {
        $nonce = $this->cliente->nonce();
        $this->crearPedido($this->pedido($this->itemsBasicos(), [], null, null, $nonce))->assertStatus(201);

        $this->crearPedido($this->pedido([['id' => 9, 'cantidad' => 1]], [], null, null, $nonce))
            ->assertStatus(409)->assertJsonPath('error.codigo', 'NONCE_REPETIDO');
        $this->assertSame(1, Pedido::count());
    }

    public function test_el_nonce_solo_se_guarda_si_las_firmas_verifican(): void
    {
        $nonce = $this->cliente->nonce();
        $this->crearPedido($this->pedido($this->itemsBasicos(), [], null, $this->atacante, $nonce))->assertStatus(403);
        $this->assertSame(0, Nonce::count());   // el atacante no pudo "quemar" el nonce

        $this->crearPedido($this->pedido($this->itemsBasicos(), [], null, null, $nonce))->assertStatus(201);
        $this->assertSame(1, Nonce::count());
        $this->assertSame($this->idA, Nonce::first()->origen);
    }

    // ---------------- Tiempo ----------------

    public function test_la_tolerancia_de_tiempo_es_de_120_segundos_inclusive(): void
    {
        $this->crearPedido($this->pedido($this->itemsBasicos(), [], null, null, null, self::T0 - 120))->assertStatus(201);
        $this->crearPedido($this->pedido($this->itemsBasicos(), [], null, null, null, self::T0 + 120))->assertStatus(201);
        $this->crearPedido($this->pedido($this->itemsBasicos(), [], null, null, null, self::T0 - 121))
            ->assertStatus(400)->assertJsonPath('error.codigo', 'TIMESTAMP_FUERA_DE_RANGO');
        $this->crearPedido($this->pedido($this->itemsBasicos(), [], null, null, null, self::T0 + 121))
            ->assertStatus(400)->assertJsonPath('error.codigo', 'TIMESTAMP_FUERA_DE_RANGO');
        $this->assertSame(2, Pedido::count());
    }

    // ---------------- E4: mensaje cifrado ----------------

    public function test_e4_un_mensaje_cifrado_modificado_se_rechaza(): void
    {
        [$r] = $this->enviar('/api/pedidos', $this->pedido($this->itemsBasicos()), true);

        $r->assertStatus(400)->assertJsonPath('error.codigo', 'MENSAJE_CORRUPTO')->assertJsonMissingPath('iv');
        $this->assertSinEfectos();
    }

    public function test_un_cuerpo_que_no_es_un_sobre_es_mensaje_corrupto(): void
    {
        $this->postJson('/api/pedidos', ['hola' => 'mundo'])->assertStatus(400)->assertJsonPath('error.codigo', 'MENSAJE_CORRUPTO');
        $this->postJson('/api/pedidos', [])->assertStatus(400)->assertJsonPath('error.codigo', 'MENSAJE_CORRUPTO');
    }

    public function test_un_sobre_cifrado_para_otra_llave_rsa_no_se_puede_abrir(): void
    {
        [$sobre] = $this->cliente->crearSobre(json_encode($this->pedido($this->itemsBasicos())), $this->pem('rsa_cliente'));

        $this->postJson('/api/pedidos', $sobre)->assertStatus(400)->assertJsonPath('error.codigo', 'MENSAJE_CORRUPTO');
    }

    // ---------------- Formato ----------------

    public function test_los_formatos_incorrectos_se_rechazan_sin_efectos(): void
    {
        $firmado = $this->pedido($this->itemsBasicos());
        $casos = [
            'items vacío' => ['items' => []],
            'items no es lista' => ['items' => ['id' => 1, 'cantidad' => 1]],
            'cantidad 0' => ['items' => [['id' => 1, 'cantidad' => 0]]],
            'cantidad 11' => ['items' => [['id' => 1, 'cantidad' => 11]]],
            'cantidad en texto' => ['items' => [['id' => 1, 'cantidad' => '1']]],
            'cantidad decimal' => ['items' => [['id' => 1, 'cantidad' => 1.5]]],
            'id 0' => ['items' => [['id' => 0, 'cantidad' => 1]]],
            'id en texto' => ['items' => [['id' => '1', 'cantidad' => 1]]],
            'ids repetidos' => ['items' => [['id' => 1, 'cantidad' => 1], ['id' => 1, 'cantidad' => 2]]],
            'campo extra en el renglón' => ['items' => [['id' => 1, 'cantidad' => 1, 'precio' => 1]]],
            'renglón sin cantidad' => ['items' => [['id' => 1]]],
            'nonce en mayúsculas' => ['nonce' => strtoupper($firmado['nonce'])],
            'nonce corto' => ['nonce' => 'abc'],
            'timestamp en texto' => ['timestamp' => (string) self::T0],
            'timestamp decimal' => ['timestamp' => self::T0 + 0.5],
            'sin cert_u' => ['cert_u' => null],
            'cert_u con campo extra' => ['cert_u' => $this->certA + ['rol' => 'admin']],
            'cert_u sin firma_b' => ['cert_u' => array_diff_key($this->certA, ['firma_b' => 1])],
            'cert_u con expiracion en texto' => ['cert_u' => ['expiracion' => (string) $this->certA['expiracion']] + $this->certA],
            'cert_u con id que no es uuid' => ['cert_u' => ['id_cuenta' => 'hola'] + $this->certA],
            'pub_u con RSA en vez de EC' => ['cert_u' => ['pub_u' => preg_replace('/-----[A-Z ]+-----|\s+/', '', $this->pem('rsa_cliente'))] + $this->certA],
            'pub_u sin relleno Base64' => ['cert_u' => ['pub_u' => rtrim($this->certA['pub_u'], '=') . 'A'] + $this->certA],
            'sin firma_u' => ['firma_u' => null],
            'firma_u que no es Base64' => ['firma_u' => '%%%'],
        ];
        $fallos = [];
        foreach ($casos as $nombre => $cambio) {
            $r = $this->crearPedido(array_merge($firmado, $cambio));
            if ($r->status() !== 400 || $r->json('error.codigo') !== 'FORMATO_INVALIDO') {
                $fallos[] = $nombre . ' => ' . $r->status() . ' ' . $r->json('error.codigo');
            }
        }

        $this->assertSame([], $fallos);
        $this->assertSinEfectos();
    }

    public function test_el_cuerpo_cifrado_debe_ser_un_objeto_json(): void
    {
        foreach (['[]', '[1,2]', '"texto"', '123', 'null', '{}', 'no es json'] as $plano) {
            [$sobre] = $this->cliente->crearSobre($plano, $this->pem('tienda_rsa'));
            $this->postJson('/api/pedidos', $sobre)->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');
        }
    }

    // ---------------- Reglas de negocio ----------------

    public function test_un_producto_inexistente_se_rechaza_pero_el_nonce_ya_quedo_consumido(): void
    {
        $this->crearPedido($this->pedido([['id' => 999, 'cantidad' => 1]]))
            ->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');

        $this->assertSame(0, Pedido::count());
        $this->assertSame(1, Nonce::count());   // la firma era válida: el nonce se gastó (contrato: se reintenta con otro)
    }

    public function test_un_producto_retirado_no_se_puede_pedir(): void
    {
        Producto::find(3)->update(['activo' => false]);

        $this->crearPedido($this->pedido($this->itemsBasicos()))->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');
        $this->assertSame(0, Pedido::count());
    }

    public function test_un_pedido_que_supera_el_monto_maximo_se_rechaza(): void
    {
        // Teclado (640) x10 + Mochila (480) x10 = 11,200 > 10,000
        $items = [['id' => 4, 'cantidad' => 10], ['id' => 12, 'cantidad' => 10]];
        $this->crearPedido($this->pedido($items))->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');

        // 640 x10 = 6,400: permitido
        $this->crearPedido($this->pedido([['id' => 12, 'cantidad' => 10]]))->assertStatus(201);
        $this->assertSame(1, Pedido::count());
    }

    // ---------------- Orden de verificación (contrato, sección 13) ----------------

    public function test_el_formato_se_valida_antes_que_el_certificado_y_la_firma(): void
    {
        $f = $this->pedido($this->itemsBasicos(), [], null, $this->atacante);   // firma inválida...
        $f['items'] = [];                                                       // ...y formato inválido
        $this->crearPedido($f)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');
    }

    public function test_el_timestamp_se_valida_antes_que_la_firma(): void
    {
        $f = $this->pedido($this->itemsBasicos(), [], null, $this->atacante, null, self::T0 - 500);
        $this->crearPedido($f)->assertJsonPath('error.codigo', 'TIMESTAMP_FUERA_DE_RANGO');
    }

    public function test_el_nonce_repetido_se_detecta_antes_que_la_firma(): void
    {
        $nonce = $this->cliente->nonce();
        $this->crearPedido($this->pedido($this->itemsBasicos(), [], null, null, $nonce))->assertStatus(201);

        $f = $this->pedido($this->itemsBasicos(), [], null, $this->atacante, $nonce);   // firma inválida con nonce usado
        $this->crearPedido($f)->assertStatus(409)->assertJsonPath('error.codigo', 'NONCE_REPETIDO');
    }

    public function test_el_certificado_se_verifica_antes_que_la_firma_del_cliente(): void
    {
        $cert = $this->certA;
        $cert['expiracion'] += 5;   // certificado inválido Y firma del cliente (A) que ya no corresponde
        $this->crearPedido($this->pedido($this->itemsBasicos(), [], $cert, $this->atacante))
            ->assertJsonPath('error.codigo', 'CERTIFICADO_INVALIDO');
    }

    // ---------------- Bitácora ----------------

    public function test_la_bitacora_registra_aceptados_y_rechazados_sin_secretos(): void
    {
        $f = $this->pedido($this->itemsBasicos());
        [$r1, $k] = $this->enviar('/api/pedidos', $f);
        $r1->assertStatus(201);
        $bad = $this->pedido($this->itemsBasicos(), [], null, $this->atacante);
        $this->crearPedido($bad)->assertStatus(403);

        $ok = Bitacora::where('resultado', 'ACEPTADO')->first();
        $this->assertSame('/api/pedidos', $ok->endpoint);
        $this->assertSame($this->idA, $ok->id_cuenta);
        $this->assertStringStartsWith('Pedido ', $ok->detalle);
        $no = Bitacora::where('resultado', 'RECHAZADO')->first();
        $this->assertSame('FIRMA_INVALIDA', $no->codigo);
        $this->assertSame($this->idA, $no->id_cuenta);   // el certificado sí había verificado

        $todo = json_encode(Bitacora::all()->toArray());
        foreach ([$f['firma_u'], $bad['firma_u'], $f['cert_u']['pub_u'], $f['nonce'], $k, base64_encode($k)] as $secreto) {
            $this->assertStringNotContainsString($secreto, $todo);
        }
    }

    // ---------------- Fallas internas ----------------

    public function test_una_falla_inesperada_responde_error_interno_sin_detalles(): void
    {
        $this->app->instance(CreadorPedidos::class, new class (new CryptoService()) extends CreadorPedidos {
            public function crear(string $idCuenta, array $items, int $ahora): Pedido
            {
                throw new RuntimeException('detalle-secreto-interno');
            }
        });

        [$r] = $this->enviar('/api/pedidos', $this->pedido($this->itemsBasicos()));

        $r->assertStatus(500)->assertJsonPath('error.codigo', 'ERROR_INTERNO');
        $this->assertStringNotContainsString('detalle-secreto-interno', $r->getContent());
        $this->assertSame('RECHAZADO', Bitacora::orderByDesc('id')->first()->resultado);
    }

    public function test_si_falla_al_guardar_un_renglon_no_queda_el_pedido(): void
    {
        PedidoItem::creating(function () {
            throw new RuntimeException('falla simulada');
        });

        [$r] = $this->enviar('/api/pedidos', $this->pedido($this->itemsBasicos()));

        $r->assertStatus(500)->assertJsonPath('error.codigo', 'ERROR_INTERNO');
        $this->assertSame(0, Pedido::count());      // la transacción deshizo el pedido
        $this->assertSame(0, PedidoItem::count());
    }
}
