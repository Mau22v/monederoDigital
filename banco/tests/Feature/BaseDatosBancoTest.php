<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Canje;
use App\Models\Cuenta;
use App\Models\Nonce;
use App\Models\Ticket;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Fase 4: pruebas de la base de datos del Banco.
 * Usan SQLite en memoria (phpunit.xml): no tocan database/database.sqlite.
 */
class BaseDatosBancoTest extends TestCase
{
    use RefreshDatabase;

    private const T0 = 1762500000;

    private function cuenta(array $o = []): Cuenta
    {
        return Cuenta::create(array_merge([
            'correo' => 'u' . bin2hex(random_bytes(4)) . '@ejemplo.com',
            'password_hash' => 'hash-de-prueba',
            'saldo' => 10000,
            'pub_u' => 'AAAA',
            'cert_expiracion' => self::T0 + 31536000,
            'cert_firma_b' => 'firma',
        ], $o));
    }

    private function ticket(Cuenta $c, array $o = []): Ticket
    {
        return Ticket::create(array_merge([
            'id_cuenta' => $c->id,
            'denominacion' => 500,
            'emision' => self::T0,
            'expiracion' => self::T0 + Ticket::VIGENCIA_SEG,
            'firma_b' => 'firma',
        ], $o));
    }

    private function canje(Cuenta $c, string $idPedido): Canje
    {
        return Canje::create(['id_pedido' => $idPedido, 'id_cuenta' => $c->id, 'monto' => 0, 'momento' => self::T0, 'firma_b' => 'f']);
    }

    private const P1 = '11111111-1111-4111-8111-111111111111';
    private const P2 = '22222222-2222-4222-8222-222222222222';

    // ---------------- Estructura ----------------

    public function test_existen_las_tablas_y_columnas_del_diseno(): void
    {
        $this->assertTrue(Schema::hasColumns('cuentas', ['id', 'correo', 'password_hash', 'saldo', 'pub_u', 'cert_expiracion', 'cert_firma_b', 'intentos_fallidos', 'bloqueada_hasta']));
        $this->assertTrue(Schema::hasColumns('tickets', ['id', 'id_cuenta', 'denominacion', 'emision', 'expiracion', 'firma_b', 'estado', 'id_pedido', 'gastado_en']));
        $this->assertTrue(Schema::hasColumns('canjes', ['id_pedido', 'id_cuenta', 'monto', 'momento', 'firma_b']));
        $this->assertTrue(Schema::hasColumns('nonces', ['nonce', 'origen', 'endpoint', 'registrado_en', 'expira_en']));
        $this->assertTrue(Schema::hasColumns('bitacora', ['id', 'momento', 'endpoint', 'resultado', 'codigo', 'id_cuenta', 'detalle', 'ip']));
    }

    // ---------------- Cuentas ----------------

    public function test_cuenta_nueva_recibe_uuid_v4_y_sus_valores(): void
    {
        $c = $this->cuenta();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $c->id);
        $fresca = Cuenta::find($c->id);
        $this->assertSame(10000, $fresca->saldo);
        $this->assertSame(0, $fresca->intentos_fallidos);
        $this->assertNull($fresca->bloqueada_hasta);
    }

    public function test_el_correo_no_se_puede_repetir(): void
    {
        $this->cuenta(['correo' => 'ana@ejemplo.com']);

        $this->expectException(QueryException::class);
        $this->cuenta(['correo' => 'ana@ejemplo.com']);
    }

    public function test_el_hash_de_la_contrasena_no_sale_al_convertir_a_json(): void
    {
        $c = $this->cuenta();

        $this->assertArrayNotHasKey('password_hash', $c->toArray());
        $this->assertStringNotContainsString('hash-de-prueba', $c->toJson());
    }

    public function test_cinco_fallos_bloquean_cinco_minutos(): void
    {
        $c = $this->cuenta();

        for ($i = 1; $i <= 4; $i++) {
            $c->registrarFalloLogin(self::T0);
            $this->assertFalse($c->estaBloqueada(self::T0), "no debe bloquear en el intento $i");
        }
        $c->registrarFalloLogin(self::T0);   // quinto

        $c = $c->fresh();
        $this->assertTrue($c->estaBloqueada(self::T0));
        $this->assertTrue($c->estaBloqueada(self::T0 + 299));
        $this->assertFalse($c->estaBloqueada(self::T0 + 300));
        $this->assertSame(0, $c->intentos_fallidos);
    }

    public function test_un_login_correcto_limpia_intentos_y_bloqueo(): void
    {
        $c = $this->cuenta();
        $c->registrarFalloLogin(self::T0);
        $c->registrarFalloLogin(self::T0);

        $c->limpiarIntentos();

        $c = $c->fresh();
        $this->assertSame(0, $c->intentos_fallidos);
        $this->assertNull($c->bloqueada_hasta);
    }

    public function test_debitar_descuenta_y_no_permite_saldo_negativo(): void
    {
        $c = $this->cuenta(['saldo' => 1000]);

        $this->assertTrue($c->debitar(400));
        $this->assertSame(600, Cuenta::find($c->id)->saldo);

        $this->assertFalse($c->debitar(700));          // más de lo que hay
        $this->assertSame(600, Cuenta::find($c->id)->saldo);

        $this->assertTrue($c->debitar(600));           // justo todo el saldo
        $this->assertSame(0, Cuenta::find($c->id)->saldo);
    }

    public function test_debitar_rechaza_montos_no_positivos(): void
    {
        $c = $this->cuenta();

        $this->expectException(InvalidArgumentException::class);
        $c->debitar(0);
    }

    // ---------------- Tickets ----------------

    public function test_solo_se_aceptan_las_denominaciones_del_contrato(): void
    {
        foreach ([500, 300, 100, 50, 20] as $d) {
            $this->assertSame($d, $this->ticket($this->cuenta(), ['denominacion' => $d])->denominacion);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->ticket($this->cuenta(), ['denominacion' => 200]);
    }

    public function test_el_estado_efectivo_activo_expirado_y_gastado(): void
    {
        $c = $this->cuenta();
        $t = $this->ticket($c);

        $this->assertSame('ACTIVO', $t->estadoEfectivo(self::T0 + 10));
        $this->assertSame('ACTIVO', $t->estadoEfectivo($t->expiracion - 1));
        $this->assertSame('EXPIRADO', $t->estadoEfectivo($t->expiracion));

        $this->canje($c, self::P1);
        Ticket::gastar([$t->id], self::P1, $c->id, self::T0 + 10);
        $this->assertSame('GASTADO', $t->fresh()->estadoEfectivo(self::T0 + 20));
        $this->assertSame('GASTADO', $t->fresh()->estadoEfectivo($t->expiracion + 5));   // gastado manda sobre vencido
    }

    public function test_gastar_marca_los_tickets_y_los_vincula_al_canje(): void
    {
        $c = $this->cuenta();
        $a = $this->ticket($c);
        $b = $this->ticket($c, ['denominacion' => 100]);
        $canje = $this->canje($c, self::P1);

        $this->assertTrue(Ticket::gastar([$a->id, $b->id], self::P1, $c->id, self::T0 + 10));

        foreach ([$a, $b] as $t) {
            $t = $t->fresh();
            $this->assertSame('GASTADO', $t->estado);
            $this->assertSame(self::P1, $t->id_pedido);
            $this->assertSame(self::T0 + 10, $t->gastado_en);
        }
        $this->assertCount(2, $canje->tickets);
    }

    public function test_un_ticket_no_se_puede_gastar_dos_veces_E2(): void
    {
        $c = $this->cuenta();
        $t = $this->ticket($c);
        $this->canje($c, self::P1);
        $this->canje($c, self::P2);

        $this->assertTrue(Ticket::gastar([$t->id], self::P1, $c->id, self::T0 + 10));
        $this->assertFalse(Ticket::gastar([$t->id], self::P2, $c->id, self::T0 + 11));

        $this->assertSame(self::P1, $t->fresh()->id_pedido);   // sigue ligado al primer canje
    }

    public function test_si_un_ticket_falla_no_se_gasta_ninguno(): void
    {
        $c = $this->cuenta();
        $a = $this->ticket($c);
        $b = $this->ticket($c);
        $d = $this->ticket($c);
        $this->canje($c, self::P1);
        $this->canje($c, self::P2);
        Ticket::gastar([$b->id], self::P1, $c->id, self::T0 + 10);   // b ya gastado

        $this->assertFalse(Ticket::gastar([$a->id, $b->id, $d->id], self::P2, $c->id, self::T0 + 20));

        $this->assertSame('ACTIVO', $a->fresh()->estado);   // se deshizo todo
        $this->assertSame('ACTIVO', $d->fresh()->estado);
        $this->assertSame(self::P1, $b->fresh()->id_pedido);
    }

    public function test_no_se_gasta_un_ticket_de_otra_cuenta(): void
    {
        $ana = $this->cuenta();
        $beto = $this->cuenta();
        $t = $this->ticket($ana);
        $this->canje($beto, self::P1);

        $this->assertFalse(Ticket::gastar([$t->id], self::P1, $beto->id, self::T0 + 10));
        $this->assertSame('ACTIVO', $t->fresh()->estado);
    }

    public function test_no_se_gasta_un_ticket_vencido(): void
    {
        $c = $this->cuenta();
        $t = $this->ticket($c);
        $this->canje($c, self::P1);

        $this->assertFalse(Ticket::gastar([$t->id], self::P1, $c->id, $t->expiracion));
        $this->assertSame('ACTIVO', $t->fresh()->estado);
    }

    public function test_gastar_sin_ids_devuelve_false(): void
    {
        $c = $this->cuenta();

        $this->assertFalse(Ticket::gastar([], self::P1, $c->id, self::T0));
    }

    public function test_los_ids_del_canje_salen_ordenados(): void
    {
        $c = $this->cuenta();
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = $this->ticket($c)->id;
        }
        $canje = $this->canje($c, self::P1);
        Ticket::gastar($ids, self::P1, $c->id, self::T0 + 10);

        $esperado = $ids;
        sort($esperado, SORT_STRING);
        $this->assertSame($esperado, $canje->idsOrdenados());
    }

    public function test_un_pedido_no_puede_canjearse_dos_veces_en_la_tabla_canjes(): void
    {
        $c = $this->cuenta();
        $this->canje($c, self::P1);

        $this->expectException(QueryException::class);
        $this->canje($c, self::P1);
    }

    public function test_las_llaves_foraneas_estan_activas(): void
    {
        $this->expectException(QueryException::class);
        Ticket::create([
            'id_cuenta' => '99999999-9999-4999-8999-999999999999',   // no existe
            'denominacion' => 500, 'emision' => self::T0, 'expiracion' => self::T0 + 10, 'firma_b' => 'f',
        ]);
    }

    public function test_la_base_de_datos_rechaza_un_estado_inventado(): void
    {
        $t = $this->ticket($this->cuenta());

        $this->expectException(QueryException::class);
        DB::table('tickets')->where('id', $t->id)->update(['estado' => 'FALSO']);
    }

    // ---------------- Nonces ----------------

    public function test_un_nonce_solo_se_registra_una_vez(): void
    {
        $n = str_repeat('a', 32);

        $this->assertFalse(Nonce::yaUsado($n));
        $this->assertTrue(Nonce::registrar($n, 'cuenta-1', '/api/tickets/solicitar', self::T0));
        $this->assertTrue(Nonce::yaUsado($n));
        $this->assertFalse(Nonce::registrar($n, 'cuenta-2', '/api/canje', self::T0 + 5));   // repetido
        $this->assertSame(1, Nonce::count());
    }

    public function test_purgar_borra_solo_los_nonces_cuya_retencion_termino(): void
    {
        Nonce::registrar(str_repeat('a', 32), 'x', '/e', self::T0);          // expira T0+900
        Nonce::registrar(str_repeat('b', 32), 'x', '/e', self::T0 + 1000);   // expira T0+1900

        $this->assertSame(0, Nonce::purgar(self::T0 + 899));
        $this->assertSame(1, Nonce::purgar(self::T0 + 900));
        $this->assertFalse(Nonce::yaUsado(str_repeat('a', 32)));
        $this->assertTrue(Nonce::yaUsado(str_repeat('b', 32)));
    }

    // ---------------- Bitácora ----------------

    public function test_la_bitacora_guarda_un_rechazo_y_recorta_el_detalle(): void
    {
        Bitacora::rechazado('/api/login', 'CREDENCIALES_INVALIDAS', self::T0, null, str_repeat('x', 600), '127.0.0.1');

        $f = Bitacora::first();
        $this->assertSame('RECHAZADO', $f->resultado);
        $this->assertSame('CREDENCIALES_INVALIDAS', $f->codigo);
        $this->assertNull($f->id_cuenta);
        $this->assertSame(500, strlen($f->detalle));
        $this->assertSame(self::T0, (int) $f->momento);
    }

    public function test_la_bitacora_guarda_una_operacion_aceptada(): void
    {
        $c = $this->cuenta();
        Bitacora::aceptado('/api/tickets/solicitar', self::T0, $c->id, 'Total 1300');

        $f = Bitacora::first();
        $this->assertSame('ACEPTADO', $f->resultado);
        $this->assertNull($f->codigo);
        $this->assertSame($c->id, $f->id_cuenta);
    }
}
