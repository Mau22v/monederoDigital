<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Pedido;
use App\Services\Canonico;
use Illuminate\Support\Carbon;
use Tests\TiendaTestCase;

/** Fase 7: POST /api/pedidos/{id}/estado. El dueño consulta su pedido; nadie más. */
class EstadoPedidoTest extends TiendaTestCase
{
    private function consultar(string $idPedido, array $cuerpo, bool $alterar = false): array
    {
        return $this->enviar("/api/pedidos/$idPedido/estado", $cuerpo, $alterar);
    }

    public function test_el_dueno_ve_su_pedido_creado_sin_comprobante(): void
    {
        $id = $this->pedidoDeA();

        [$r, $k] = $this->consultar($id, $this->consulta($id));

        $r->assertStatus(200)->assertJsonStructure(['iv', 'ct'])->assertJsonMissingPath('estado');
        $this->assertSame(['id_pedido' => $id, 'estado' => 'CREADO', 'comprobante' => null], $this->abrir($r, $k));
    }

    public function test_el_pedido_vence_exactamente_a_los_600_segundos(): void
    {
        $id = $this->pedidoDeA();

        $this->travelTo(Carbon::createFromTimestampUTC(self::T0 + 599));
        [$r, $k] = $this->consultar($id, $this->consulta($id));
        $this->assertSame('CREADO', $this->abrir($r, $k)['estado']);

        $this->travelTo(Carbon::createFromTimestampUTC(self::T0 + 600));
        [$r, $k] = $this->consultar($id, $this->consulta($id));
        $this->assertSame('EXPIRADO', $this->abrir($r, $k)['estado']);
        $this->assertSame('CREADO', Pedido::find($id)->estado);   // en la base sigue CREADO: EXPIRADO se calcula
    }

    public function test_un_pedido_pagado_o_rechazado_conserva_su_estado_aunque_pase_el_tiempo(): void
    {
        $id = $this->pedidoDeA();
        Pedido::find($id)->update(['estado' => 'PAGADO']);
        $this->travelTo(Carbon::createFromTimestampUTC(self::T0 + 5000));

        [$r, $k] = $this->consultar($id, $this->consulta($id));

        $this->assertSame('PAGADO', $this->abrir($r, $k)['estado']);
    }

    public function test_el_pedido_de_otra_cuenta_responde_igual_que_uno_inexistente(): void
    {
        $id = $this->pedidoDeA();

        [$ajeno] = $this->consultar($id, $this->consulta($id, [], $this->certB, $this->firmanteB));
        [$noExiste] = $this->consultar('11111111-1111-4111-8111-111111111111', $this->consulta('11111111-1111-4111-8111-111111111111'));

        $ajeno->assertStatus(404)->assertJsonPath('error.codigo', 'PEDIDO_NO_ENCONTRADO');
        $noExiste->assertStatus(404)->assertJsonPath('error.codigo', 'PEDIDO_NO_ENCONTRADO');
        $this->assertSame($ajeno->json(), $noExiste->json());
    }

    public function test_un_id_que_no_es_uuid_es_formato_invalido(): void
    {
        [$r] = $this->consultar('abc', $this->consulta('abc'));

        $r->assertStatus(400)->assertJsonPath('error.codigo', 'FORMATO_INVALIDO');
    }

    public function test_e3_firma_alterada_o_de_otro_tipo_no_sirve(): void
    {
        $id = $this->pedidoDeA();

        $f = $this->consulta($id);
        $bytes = base64_decode($f['firma_u']);
        $bytes[10] = chr(ord($bytes[10]) ^ 0x01);
        $f['firma_u'] = base64_encode($bytes);
        [$r] = $this->consultar($id, $f);
        $r->assertStatus(403)->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');

        // Una firma de "crear_pedido" no vale como "consulta_pedido".
        $g = $this->consulta($id);
        $g['firma_u'] = $this->firmanteA->firmar(Canonico::crearPedido($this->idA, $this->itemsBasicos(), $g['nonce'], $g['timestamp']));
        [$r] = $this->consultar($id, $g);
        $r->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');

        // La firma de la consulta del pedido X no vale para el pedido Y (el id va dentro de la cadena).
        $otro = $this->pedidoDeA();
        $h = $this->consulta($id);
        [$r] = $this->consultar($otro, $h);
        $r->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
    }

    public function test_certificado_invalido_se_rechaza(): void
    {
        $id = $this->pedidoDeA();
        $cert = $this->certA;
        $cert['pub_u'] = $this->pubBase64('atacante');

        [$r] = $this->consultar($id, $this->consulta($id, [], $cert, $this->atacante));

        $r->assertStatus(403)->assertJsonPath('error.codigo', 'CERTIFICADO_INVALIDO');
    }

    public function test_repetir_la_misma_consulta_es_nonce_repetido(): void
    {
        $id = $this->pedidoDeA();
        [$sobre] = $this->cliente->crearSobre(json_encode($this->consulta($id)), $this->pem('tienda_rsa'));

        $this->postJson("/api/pedidos/$id/estado", $sobre)->assertStatus(200);
        $this->postJson("/api/pedidos/$id/estado", $sobre)->assertStatus(409)->assertJsonPath('error.codigo', 'NONCE_REPETIDO');
    }

    public function test_e4_mensaje_alterado_y_tiempo_fuera_de_rango(): void
    {
        $id = $this->pedidoDeA();

        [$r] = $this->consultar($id, $this->consulta($id), true);
        $r->assertStatus(400)->assertJsonPath('error.codigo', 'MENSAJE_CORRUPTO');

        [$r] = $this->consultar($id, $this->consulta($id, [], null, null, null, self::T0 + 500));
        $r->assertStatus(400)->assertJsonPath('error.codigo', 'TIMESTAMP_FUERA_DE_RANGO');
    }

    public function test_la_consulta_queda_en_la_bitacora_con_la_ruta_generica(): void
    {
        $id = $this->pedidoDeA();
        $this->consultar($id, $this->consulta($id));

        $fila = Bitacora::orderByDesc('id')->first();
        $this->assertSame('/api/pedidos/{id}/estado', $fila->endpoint);
        $this->assertSame('ACEPTADO', $fila->resultado);
        $this->assertSame($this->idA, $fila->id_cuenta);
    }
}
