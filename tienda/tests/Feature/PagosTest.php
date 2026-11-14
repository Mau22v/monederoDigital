<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Nonce;
use App\Models\Pedido;
use App\Services\Canonico;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TiendaTestCase;

/**
 * Fase 8 (lado Tienda): POST /api/pedidos/{id}/pago y el estado con comprobante.
 * Cubre el pago, E1 (ticket alterado), E2 (ticket reutilizado), E3 (firmas alteradas), E4 (sobre alterado),
 * las fallas del banco, la conciliación tras una red cortada y la bitácora.
 */
class PagosTest extends TiendaTestCase
{
    // ---------------- Pago correcto ----------------

    public function test_un_pago_valido_marca_el_pedido_pagado_y_devuelve_comprobante_y_recibo(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $ids = array_column($t, 'id');
        sort($ids, SORT_STRING);

        [$r, $k] = $this->enviar("/api/pedidos/$id/pago", $this->pago($id, $t));

        $r->assertStatus(200)->assertJsonStructure(['iv', 'ct'])->assertJsonMissingPath('comprobante');
        $d = $this->abrir($r, $k);
        $c = $d['comprobante'];
        $this->assertSame($id, $c['id_pedido']);
        $this->assertSame($this->idA, $c['id_cuenta']);
        $this->assertSame(490, $c['monto']);
        $this->assertSame('PAGADO', $c['estado']);
        $this->assertSame(self::T0, $c['timestamp']);
        $this->assertTrue($this->cliente->verificar(Canonico::comprobante($id, $this->idA, 490, 'PAGADO', self::T0), $c['firma_t'], $this->pem('tienda_ec')), 'firma del comprobante');
        $this->assertFalse($this->cliente->verificar(Canonico::comprobante($id, $this->idA, 500, 'PAGADO', self::T0), $c['firma_t'], $this->pem('tienda_ec')));

        $rec = $d['recibo_canje'];
        $this->assertSame($ids, $rec['ids']);
        $this->assertSame(490, $rec['monto']);
        $this->assertTrue($this->cliente->verificar(Canonico::reciboCanje($id, 490, $ids, $rec['timestamp']), $rec['firma_b'], $this->pem('banco_ec')), 'firma del recibo');

        $p = Pedido::find($id);
        $this->assertSame('PAGADO', $p->estado);
        $this->assertSame($ids, $p->idsTickets());
        $this->assertSame(1, $this->bancoFalso->veces('canjear'));
    }

    public function test_al_banco_se_le_envian_el_monto_los_ids_y_la_orden_firmada_por_el_cliente(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $cuerpo = $this->pago($id, $t);

        $this->pagar($id, $cuerpo)->assertStatus(200);

        $u = $this->bancoFalso->ultimoCanje;
        $this->assertSame(490, $u['monto']);
        $this->assertSame($cuerpo['orden']['nonce'], $u['orden']['nonce']);
        $this->assertSame($cuerpo['orden']['timestamp'], $u['orden']['timestamp']);
        $this->assertSame($cuerpo['firma_u'], $u['orden']['firma_u']);
    }

    public function test_el_estado_trae_el_comprobante_solo_cuando_esta_pagado(): void
    {
        $id = $this->pedidoDeA();
        $antes = $this->estadoDe($id);
        $this->assertSame('CREADO', $antes['estado']);
        $this->assertNull($antes['comprobante']);

        $pago = $this->pagarOk($id);
        $despues = $this->estadoDe($id);

        $this->assertSame('PAGADO', $despues['estado']);
        $this->assertSame($pago['comprobante'], $despues['comprobante']);
        $this->assertSame(0, $this->bancoFalso->veces('consultar'), 'un pedido pagado no necesita preguntar al banco');
    }

    public function test_un_pedido_pagado_sigue_pagado_aunque_pase_el_tiempo_de_expiracion(): void
    {
        $id = $this->pedidoDeA();
        $this->pagarOk($id);
        $this->travelTo(Carbon::createFromTimestampUTC(self::T0 + 5000));

        $e = $this->estadoDe($id);
        $this->assertSame('PAGADO', $e['estado']);
        $this->assertNotNull($e['comprobante']);
    }

    // ---------------- Idempotencia y duplicados ----------------

    public function test_repetir_el_pago_con_los_mismos_tickets_devuelve_el_mismo_comprobante_sin_llamar_al_banco(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $primero = $this->pagarOk($id, $t);

        [$r, $k] = $this->enviar("/api/pedidos/$id/pago", $this->pago($id, $t));   // orden nueva (otro nonce)

        $r->assertStatus(200);
        $this->assertSame($primero, $this->abrir($r, $k));
        $this->assertSame(1, $this->bancoFalso->veces('canjear'));
    }

    public function test_repetir_el_pago_despues_de_expirar_el_pedido_sigue_devolviendo_el_comprobante(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $primero = $this->pagarOk($id, $t);
        $this->travelTo(Carbon::createFromTimestampUTC(self::T0 + 700));

        [$r, $k] = $this->enviar("/api/pedidos/$id/pago", $this->pago($id, $t));

        $r->assertStatus(200);
        $this->assertSame($primero, $this->abrir($r, $k));
    }

    public function test_un_pedido_pagado_no_se_puede_pagar_con_otros_tickets(): void
    {
        $id = $this->pedidoDeA();
        $this->pagarOk($id);
        $otros = $this->ticketsDe();

        $this->pagar($id, $this->pago($id, $otros))->assertStatus(409)->assertJsonPath('error.codigo', 'PEDIDO_DUPLICADO');
        $this->assertSame(1, $this->bancoFalso->veces('canjear'));
        $this->assertSame([], array_intersect(array_column($otros, 'id'), array_keys($this->bancoFalso->gastados)), 'los tickets nuevos no se gastaron');
    }

    public function test_un_pedido_pagado_no_se_puede_pagar_otra_vez_con_otro_monto_aunque_los_tickets_sean_los_mismos(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $this->pagarOk($id, $t);

        $this->pagar($id, $this->pago($id, $t, ['monto' => 500]))->assertStatus(409)->assertJsonPath('error.codigo', 'PEDIDO_DUPLICADO');
        $this->assertSame(1, $this->bancoFalso->veces('canjear'));
    }

    // ---------------- E1: ticket alterado ----------------

    public function test_E1_un_ticket_con_la_denominacion_cambiada_se_rechaza_sin_llamar_al_banco(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $antes = Nonce::count();
        $t[3]['denominacion'] = 500;   // un ticket de $20 convertido en uno de $500 DESPUÉS de que el banco lo firmó

        $this->pagar($id, $this->pago($id, $t))->assertStatus(403)->assertJsonPath('error.codigo', 'TICKET_INVALIDO');

        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
        $this->assertSame($antes, Nonce::count(), 'el nonce no se guarda si un ticket falló');
        $this->assertSame('CREADO', Pedido::find($id)->estado);
    }

    public function test_E1_un_ticket_con_la_fecha_de_expiracion_extendida_se_rechaza(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $t[0]['expiracion'] += 86400;

        $this->pagar($id, $this->pago($id, $t))->assertStatus(403)->assertJsonPath('error.codigo', 'TICKET_INVALIDO');
        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
    }

    public function test_E1_un_ticket_fabricado_por_el_cliente_con_su_propia_llave_se_rechaza(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $t[0] = $this->ticket(300, $this->idA, $this->atacante);   // "firmado" por quien no es el banco

        $this->pagar($id, $this->pago($id, $t))->assertStatus(403)->assertJsonPath('error.codigo', 'TICKET_INVALIDO');
        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
    }

    public function test_E1_un_ticket_autentico_de_otra_persona_se_rechaza(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $t[0] = $this->ticket(300, $this->idB);   // ticket legítimo de B

        $this->pagar($id, $this->pago($id, $t))->assertStatus(403)->assertJsonPath('error.codigo', 'TICKET_INVALIDO');
        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
    }

    public function test_E1_cambiar_el_id_de_un_ticket_invalida_su_firma(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $t[1]['id'] = (string) Str::uuid();

        $this->pagar($id, $this->pago($id, $t))->assertStatus(403)->assertJsonPath('error.codigo', 'TICKET_INVALIDO');
    }

    // ---------------- E2: ticket reutilizado ----------------

    public function test_E2_los_mismos_tickets_no_sirven_para_pagar_un_segundo_pedido(): void
    {
        $uno = $this->pedidoDeA();
        $dos = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $this->pagarOk($uno, $t);

        $this->pagar($dos, $this->pago($dos, $t))->assertStatus(409)->assertJsonPath('error.codigo', 'TICKET_GASTADO');

        $p = Pedido::find($dos);
        $this->assertSame('CREADO', $p->estado, 'el segundo pedido NO se pagó');
        $this->assertFalse($p->tieneIntento(), 'el rechazo definitivo del banco no deja intentos pendientes');
        $this->assertNull($this->estadoDe($dos)['comprobante']);
    }

    public function test_E2_un_solo_ticket_gastado_basta_para_rechazar_el_pago(): void
    {
        $uno = $this->pedidoDeA();
        $dos = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $this->pagarOk($uno, $t);
        $mezcla = [$t[0], ...$this->ticketsDe([100, 50, 20, 20])];   // 300 reutilizado + 190 nuevos

        $this->pagar($dos, $this->pago($dos, $mezcla))->assertStatus(409)->assertJsonPath('error.codigo', 'TICKET_GASTADO');
        $this->assertSame('CREADO', Pedido::find($dos)->estado);
    }

    // ---------------- E3: firmas ----------------

    public function test_E3_una_firma_de_la_orden_alterada_se_rechaza_sin_efectos(): void
    {
        $id = $this->pedidoDeA();
        $c = $this->pago($id, $this->ticketsDe());
        $b = base64_decode($c['firma_u'], true);
        $b[10] = chr(ord($b[10]) ^ 0x01);
        $c['firma_u'] = base64_encode($b);
        $antes = Nonce::count();

        $this->pagar($id, $c)->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');

        $this->assertSame($antes, Nonce::count());
        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
        $this->assertSame('CREADO', Pedido::find($id)->estado);
    }

    public function test_E3_una_orden_firmada_por_otra_llave_se_rechaza(): void
    {
        $id = $this->pedidoDeA();

        $this->pagar($id, $this->pago($id, $this->ticketsDe(), ['firmante' => $this->atacante]))->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
    }

    public function test_E3_cambiar_los_tickets_despues_de_firmar_la_orden_se_rechaza(): void
    {
        $id = $this->pedidoDeA();
        $c = $this->pago($id, $this->ticketsDe());
        $c['orden']['tickets'] = $this->ticketsDe();   // otros tickets legítimos, la firma era de los primeros

        $this->pagar($id, $c)->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
    }

    public function test_E3_cambiar_el_monto_despues_de_firmar_la_orden_se_rechaza(): void
    {
        $id = $this->pedidoDeA();
        $c = $this->pago($id, $this->ticketsDe());
        $c['orden']['monto'] = 20;

        $this->pagar($id, $c)->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
    }

    public function test_E3_la_firma_de_otro_tipo_de_mensaje_no_sirve_como_orden(): void
    {
        $id = $this->pedidoDeA();
        $c = $this->pago($id, $this->ticketsDe());
        $c['firma_u'] = $this->firmanteA->firmar(Canonico::consultaPedido($id, $this->idA, $c['orden']['nonce'], $c['orden']['timestamp']));

        $this->pagar($id, $c)->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
    }

    // ---------------- Certificado y dueño del pedido ----------------

    public function test_un_cert_u_vencido_se_rechaza(): void
    {
        $id = $this->pedidoDeA();
        $vencido = $this->certificado($this->idA, $this->pubBase64('a'), self::T0);

        $this->pagar($id, $this->pago($id, $this->ticketsDe(), ['cert' => $vencido]))->assertStatus(403)->assertJsonPath('error.codigo', 'CERTIFICADO_INVALIDO');
        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
    }

    public function test_un_cert_u_firmado_por_otro_se_rechaza(): void
    {
        $id = $this->pedidoDeA();
        $falso = $this->certificado($this->idA, $this->pubBase64('atacante'), null, $this->atacante);

        $this->pagar($id, $this->pago($id, $this->ticketsDe(), ['cert' => $falso, 'firmante' => $this->atacante]))
            ->assertStatus(403)->assertJsonPath('error.codigo', 'CERTIFICADO_INVALIDO');
    }

    public function test_B_no_puede_pagar_el_pedido_de_A_ni_siquiera_saber_que_existe(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe([300, 100, 50, 20, 20], $this->idB);

        $this->pagar($id, $this->pago($id, $t, ['cert' => $this->certB, 'firmante' => $this->firmanteB]))
            ->assertStatus(404)->assertJsonPath('error.codigo', 'PEDIDO_NO_ENCONTRADO');
        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
        $this->assertSame('CREADO', Pedido::find($id)->estado);
    }

    public function test_un_pedido_inexistente_es_404(): void
    {
        $id = (string) Str::uuid();

        $this->pagar($id, $this->pago($id, $this->ticketsDe()))->assertStatus(404)->assertJsonPath('error.codigo', 'PEDIDO_NO_ENCONTRADO');
    }

    // ---------------- Monto, vigencia ----------------

    public function test_si_la_orden_trae_otro_monto_que_el_pedido_se_rechaza(): void
    {
        $id = $this->pedidoDeA();   // 490
        $t = $this->ticketsDe([300, 100, 100]);   // 500, y la orden (firmada) dice 500

        $this->pagar($id, $this->pago($id, $t))->assertStatus(422)->assertJsonPath('error.codigo', 'MONTO_INCORRECTO');
        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
    }

    public function test_si_los_tickets_suman_el_pedido_pero_la_orden_firma_otro_monto_se_rechaza(): void
    {
        $id = $this->pedidoDeA();   // 490
        $t = $this->ticketsDe();    // suman 490, pero el cliente firmó una orden de 500

        $this->pagar($id, $this->pago($id, $t, ['monto' => 500]))->assertStatus(422)->assertJsonPath('error.codigo', 'MONTO_INCORRECTO');
        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
    }

    public function test_si_los_tickets_no_suman_el_monto_del_pedido_se_rechaza_sin_cambio(): void
    {
        $id = $this->pedidoDeA();   // 490
        $t = $this->ticketsDe([300, 100, 50, 20]);   // 470

        $this->pagar($id, $this->pago($id, $t, ['monto' => 490]))->assertStatus(422)->assertJsonPath('error.codigo', 'MONTO_INCORRECTO');
        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
    }

    public function test_un_pedido_expirado_no_se_puede_pagar(): void
    {
        $id = $this->pedidoDeA();
        $this->travelTo(Carbon::createFromTimestampUTC(self::T0 + 600));

        $this->pagar($id, $this->pago($id, $this->ticketsDe()))->assertStatus(409)->assertJsonPath('error.codigo', 'PEDIDO_EXPIRADO');
        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
    }

    public function test_un_segundo_antes_de_expirar_el_pedido_todavia_se_puede_pagar(): void
    {
        $id = $this->pedidoDeA();
        $this->travelTo(Carbon::createFromTimestampUTC(self::T0 + 599));

        $this->pagar($id, $this->pago($id, $this->ticketsDe()))->assertStatus(200);
    }

    public function test_un_ticket_vencido_se_rechaza(): void
    {
        $id = $this->pedidoDeA();
        $t = [$this->ticket(300, null, null, self::T0 - 3000000, self::T0), ...$this->ticketsDe([100, 50, 20, 20])];

        $this->pagar($id, $this->pago($id, $t))->assertStatus(409)->assertJsonPath('error.codigo', 'TICKET_EXPIRADO');
        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
    }

    // ---------------- Nonce y timestamp (de la orden) ----------------

    public function test_repetir_el_mismo_mensaje_cifrado_es_nonce_repetido(): void
    {
        $id = $this->pedidoDeA();
        [$sobre] = $this->cliente->crearSobre(json_encode($this->pago($id, $this->ticketsDe())), $this->pem('tienda_rsa'));

        $this->postJson("/api/pedidos/$id/pago", $sobre)->assertStatus(200);
        $this->postJson("/api/pedidos/$id/pago", $sobre)->assertStatus(409)->assertJsonPath('error.codigo', 'NONCE_REPETIDO');
        $this->assertSame(1, $this->bancoFalso->veces('canjear'));
    }

    public function test_el_nonce_de_la_orden_no_se_puede_reutilizar_en_otra_orden(): void
    {
        $id = $this->pedidoDeA();
        $nonce = $this->cliente->nonce();
        $this->pagar($id, $this->pago($id, $this->ticketsDe(), ['nonce' => $nonce]))->assertStatus(200);
        $otro = $this->pedidoDeA();

        $this->pagar($otro, $this->pago($otro, $this->ticketsDe(), ['nonce' => $nonce]))->assertStatus(409)->assertJsonPath('error.codigo', 'NONCE_REPETIDO');
    }

    public function test_el_timestamp_de_la_orden_se_valida_con_tolerancia_de_120_segundos(): void
    {
        $id = $this->pedidoDeA();

        $this->pagar($id, $this->pago($id, $this->ticketsDe(), ['ts' => self::T0 + 121]))->assertStatus(400)->assertJsonPath('error.codigo', 'TIMESTAMP_FUERA_DE_RANGO');
        $this->pagar($id, $this->pago($id, $this->ticketsDe(), ['ts' => self::T0 - 121]))->assertStatus(400)->assertJsonPath('error.codigo', 'TIMESTAMP_FUERA_DE_RANGO');
        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
        $this->pagar($id, $this->pago($id, $this->ticketsDe(), ['ts' => self::T0 + 120]))->assertStatus(200);
    }

    // ---------------- E4 y formato ----------------

    public function test_E4_un_sobre_alterado_se_rechaza(): void
    {
        $id = $this->pedidoDeA();

        $this->pagar($id, $this->pago($id, $this->ticketsDe()), true)->assertStatus(400)->assertJsonPath('error.codigo', 'MENSAJE_CORRUPTO');
        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
    }

    public function test_formatos_invalidos_se_rechazan(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $base = $this->pago($id, $t);
        $con = fn (array $cambio) => array_merge($base['orden'], $cambio);
        $casos = [
            'orden de otro pedido' => ['orden' => $con(['id_pedido' => (string) Str::uuid()])],
            'orden ausente' => ['orden' => null],
            'orden con campo extra' => ['orden' => $con(['extra' => 1])],
            'monto texto' => ['orden' => $con(['monto' => '490'])],
            'monto cero' => ['orden' => $con(['monto' => 0])],
            'monto sobre el máximo' => ['orden' => $con(['monto' => 10001])],
            'tickets vacío' => ['orden' => $con(['tickets' => []])],
            'tickets repetidos' => ['orden' => $con(['tickets' => [$t[0], $t[0]]])],
            'más de 50 tickets' => ['orden' => $con(['tickets' => array_fill(0, 51, $t[0])])],
            'ticket con campo extra' => ['orden' => $con(['tickets' => [$t[0] + ['x' => 1]]])],
            'ticket sin firma' => ['orden' => $con(['tickets' => [array_diff_key($t[0], ['firma_b' => 1])]])],
            'denominación inventada' => ['orden' => $con(['tickets' => [array_merge($t[0], ['denominacion' => 7])]])],
            'nonce corto' => ['orden' => $con(['nonce' => 'abc'])],
            'cert ausente' => ['cert_u' => null],
            'firma_u ausente' => ['firma_u' => null],
        ];
        foreach ($casos as $nombre => $cambio) {
            $this->pagar($id, array_merge($base, $cambio))->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');
        }
        $this->assertSame(0, $this->bancoFalso->veces('canjear'));
    }

    public function test_la_orden_de_otro_pedido_en_la_url_se_rechaza(): void
    {
        $a = $this->pedidoDeA();
        $b = $this->pedidoDeA();

        // Una orden firmada para el pedido A no se puede enviar a la ruta del pedido B
        $this->pagar($b, $this->pago($a, $this->ticketsDe()))->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');
    }

    // ---------------- Fallas del banco y conciliación ----------------

    public function test_si_el_banco_no_responde_el_pago_falla_con_error_interno_y_el_pedido_sigue_creado(): void
    {
        $id = $this->pedidoDeA();
        $this->bancoFalso->sinRed = true;

        $r = $this->pagar($id, $this->pago($id, $this->ticketsDe()));

        $r->assertStatus(500)->assertJsonPath('error.codigo', 'ERROR_INTERNO');
        $this->assertSame('CREADO', Pedido::find($id)->estado);
        $this->assertTrue(Pedido::find($id)->tieneIntento());
        $this->assertStringNotContainsString('sin red', $r->getContent(), 'el detalle técnico no sale en la respuesta');
    }

    public function test_tras_una_red_cortada_sin_canje_el_estado_sigue_creado_y_se_puede_reintentar(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $this->bancoFalso->sinRed = true;
        $this->pagar($id, $this->pago($id, $t))->assertStatus(500);

        $this->bancoFalso->sinRed = false;
        $e = $this->estadoDe($id);
        $this->assertSame('CREADO', $e['estado']);
        $this->assertNull($e['comprobante']);

        $r = $this->pagarOk($id, $t);   // el cliente reintenta con los MISMOS tickets y una orden nueva
        $this->assertSame('PAGADO', $r['comprobante']['estado']);
    }

    public function test_tras_perder_la_respuesta_el_estado_concilia_con_el_banco_y_entrega_el_comprobante(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $this->bancoFalso->perderRespuesta = true;
        $this->pagar($id, $this->pago($id, $t))->assertStatus(500)->assertJsonPath('error.codigo', 'ERROR_INTERNO');
        $this->assertSame('CREADO', Pedido::find($id)->estado, 'la tienda no sabe todavía que el banco cobró');

        $e = $this->estadoDe($id);   // el cliente NO reenvía los tickets: solo consulta

        $this->assertSame('PAGADO', $e['estado']);
        $this->assertNotNull($e['comprobante']);
        $this->assertTrue($this->cliente->verificar(Canonico::comprobante($id, $this->idA, 490, 'PAGADO', self::T0), $e['comprobante']['firma_t'], $this->pem('tienda_ec')));
        $this->assertSame(1, $this->bancoFalso->veces('canjear'), 'no se volvió a canjear');
        $this->assertSame(1, $this->bancoFalso->veces('consultar'));
    }

    public function test_tras_perder_la_respuesta_reintentar_el_pago_concilia_sin_canjear_otra_vez(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $this->bancoFalso->perderRespuesta = true;
        $this->pagar($id, $this->pago($id, $t))->assertStatus(500);
        $this->bancoFalso->perderRespuesta = false;

        $r = $this->pagarOk($id, $t);

        $this->assertSame('PAGADO', $r['comprobante']['estado']);
        $this->assertSame(1, $this->bancoFalso->veces('canjear'));
    }

    public function test_un_pedido_que_expiro_con_el_pago_a_medias_se_concilia_como_pagado(): void
    {
        $id = $this->pedidoDeA();
        $this->bancoFalso->perderRespuesta = true;
        $this->pagar($id, $this->pago($id, $this->ticketsDe()))->assertStatus(500);
        $this->travelTo(Carbon::createFromTimestampUTC(self::T0 + 700));   // ya pasó la vigencia del pedido

        $e = $this->estadoDe($id);

        $this->assertSame('PAGADO', $e['estado'], 'el dinero ya salió: el pedido no puede quedar EXPIRADO');
        $this->assertNotNull($e['comprobante']);
    }

    public function test_si_la_conciliacion_no_alcanza_al_banco_el_estado_responde_sin_fallar(): void
    {
        $id = $this->pedidoDeA();
        $this->bancoFalso->perderRespuesta = true;
        $this->pagar($id, $this->pago($id, $this->ticketsDe()))->assertStatus(500);
        $this->bancoFalso->sinRed = true;

        $e = $this->estadoDe($id);

        $this->assertSame('CREADO', $e['estado']);
        $this->assertNull($e['comprobante']);
    }

    public function test_si_el_banco_ya_canjeo_con_otros_tickets_el_pago_es_duplicado_y_el_pedido_queda_pagado(): void
    {
        $id = $this->pedidoDeA();
        $primeros = $this->ticketsDe();
        $this->bancoFalso->canjeoPorSuCuenta($id, 490, array_column($primeros, 'id'), self::T0);   // el banco cobró, la tienda no se enteró

        $this->pagar($id, $this->pago($id, $this->ticketsDe()))->assertStatus(409)->assertJsonPath('error.codigo', 'PEDIDO_DUPLICADO');

        $p = Pedido::find($id);
        $ids = array_column($primeros, 'id');
        sort($ids, SORT_STRING);
        $this->assertSame('PAGADO', $p->estado);
        $this->assertSame($ids, $p->idsTickets(), 'el pedido queda pagado con los tickets que el banco canjeó');
    }

    // ---------------- Errores del banco ----------------

    public function test_los_errores_del_cliente_que_da_el_banco_se_informan_tal_cual(): void
    {
        $casos = ['TICKET_GASTADO' => 409, 'TICKET_EXPIRADO' => 409, 'TICKET_INVALIDO' => 403, 'MONTO_INCORRECTO' => 422, 'CERTIFICADO_INVALIDO' => 403, 'PEDIDO_DUPLICADO' => 409];
        foreach ($casos as $codigo => $http) {
            $id = $this->pedidoDeA();
            $this->bancoFalso->rechazo = $codigo;

            $this->pagar($id, $this->pago($id, $this->ticketsDe()))->assertStatus($http)->assertJsonPath('error.codigo', $codigo);
            $this->assertSame('CREADO', Pedido::find($id)->estado);
        }
    }

    public function test_un_error_del_banco_que_no_es_culpa_del_cliente_sale_como_error_interno(): void
    {
        foreach (['FIRMA_INVALIDA', 'FORMATO_INVALIDO', 'NONCE_REPETIDO', 'TIMESTAMP_FUERA_DE_RANGO', 'ERROR_INTERNO'] as $codigo) {
            $id = $this->pedidoDeA();
            $this->bancoFalso->rechazo = $codigo;

            $this->pagar($id, $this->pago($id, $this->ticketsDe()))->assertStatus(500)->assertJsonPath('error.codigo', 'ERROR_INTERNO');
            $this->assertSame('CREADO', Pedido::find($id)->estado);
        }
    }

    public function test_un_recibo_con_firma_alterada_no_se_acepta_y_el_pedido_no_queda_pagado(): void
    {
        $id = $this->pedidoDeA();
        $this->bancoFalso->reciboAlterado = ['firma_b' => $this->atacante->firmar('recibo falso')];

        $this->pagar($id, $this->pago($id, $this->ticketsDe()))->assertStatus(500)->assertJsonPath('error.codigo', 'ERROR_INTERNO');
        $this->assertSame('CREADO', Pedido::find($id)->estado);
    }

    public function test_un_recibo_de_otro_monto_no_se_acepta(): void
    {
        $id = $this->pedidoDeA();
        $this->bancoFalso->reciboAlterado = ['monto' => 20];

        $this->pagar($id, $this->pago($id, $this->ticketsDe()))->assertStatus(500);
        $this->assertSame('CREADO', Pedido::find($id)->estado);
    }

    public function test_un_recibo_de_otro_pedido_no_se_acepta(): void
    {
        $id = $this->pedidoDeA();
        $this->bancoFalso->reciboAlterado = ['id_pedido' => (string) Str::uuid()];

        $this->pagar($id, $this->pago($id, $this->ticketsDe()))->assertStatus(500);
        $this->assertSame('CREADO', Pedido::find($id)->estado);
    }

    // ---------------- Peticiones simultáneas ----------------

    public function test_si_otra_peticion_paga_primero_se_devuelve_lo_que_esa_peticion_guardo(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $ids = array_column($t, 'id');
        sort($ids, SORT_STRING);
        // Justo después de canjear en el banco, "otra petición" marca el pedido PAGADO con su propio comprobante.
        $this->bancoFalso->alCanjear = function (string $idPedido) use ($ids) {
            $firma = $this->banco->firmar('x');   // marca reconocible
            Pedido::query()->where('id', $idPedido)->update([
                'estado' => 'PAGADO', 'ids_tickets' => implode(',', $ids), 'pagado_en' => self::T0 - 1,
                'firma_comprobante' => $firma, 'recibo_ts' => self::T0, 'recibo_firma_b' => $firma,
            ]);
        };

        [$r, $k] = $this->enviar("/api/pedidos/$id/pago", $this->pago($id, $t));

        $r->assertStatus(200);
        $this->assertSame(self::T0 - 1, $this->abrir($r, $k)['comprobante']['timestamp'], 'se conserva el pago de la primera petición');
    }

    // ---------------- Bitácora ----------------

    public function test_la_bitacora_registra_pagos_y_rechazos_sin_firmas_ni_ids_de_tickets(): void
    {
        $id = $this->pedidoDeA();
        $t = $this->ticketsDe();
        $c = $this->pago($id, $t);
        $this->pagar($id, $c)->assertStatus(200);
        $dos = $this->pedidoDeA();
        $this->pagar($dos, $this->pago($dos, $t))->assertStatus(409);
        $alterados = $this->ticketsDe();
        $alterados[0]['denominacion'] = 500;
        $this->pagar($dos, $this->pago($dos, $alterados))->assertStatus(403);

        $texto = json_encode(Bitacora::all()->toArray(), JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('pagado por $490', $texto);
        $this->assertStringContainsString('TICKET_GASTADO', $texto);
        $this->assertStringContainsString('TICKET_INVALIDO', $texto);
        $this->assertStringContainsString('/api/pedidos/{id}/pago', $texto);
        $this->assertStringNotContainsString($c['firma_u'], $texto);
        $this->assertStringNotContainsString($t[0]['firma_b'], $texto);
        $this->assertStringNotContainsString($t[0]['id'], $texto);
    }
}
