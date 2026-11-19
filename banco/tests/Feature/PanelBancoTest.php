<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Canje;
use App\Models\Cuenta;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 9: panel web de solo lectura del banco.
 * Comprueba que las páginas respondan, que los filtros funcionen, que el panel no escriba
 * y que nunca muestre secretos (docs/Guia_Paneles_Web.md, sección 4).
 * Usan SQLite en memoria (phpunit.xml): no tocan database/database.sqlite.
 */
class PanelBancoTest extends TestCase
{
    use RefreshDatabase;

    private const PEDIDO = '33333333-3333-4333-8333-333333333333';
    private const XSS = '<script>alert("xss")</script>';

    /** Valores que jamás deben aparecer en el HTML del panel. */
    private const SECRETOS = [
        'HASH-SECRETO-NO-MOSTRAR', 'PUB-U-NO-MOSTRAR', 'CERT-FIRMA-NO-MOSTRAR',
        'FIRMA-CANJE-NO-MOSTRAR', 'FIRMA-TICKET-NO-MOSTRAR',
    ];

    private const PAGINAS = ['/panel', '/panel/bitacora', '/panel/cuentas', '/panel/tickets', '/panel/canjes'];

    private Cuenta $cuenta;
    private int $ahora;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahora = time();

        $this->cuenta = Cuenta::create([
            'correo' => 'ana@ejemplo.com',
            'password_hash' => 'HASH-SECRETO-NO-MOSTRAR',
            'saldo' => 9200,
            'pub_u' => 'PUB-U-NO-MOSTRAR',
            'cert_expiracion' => $this->ahora + Cuenta::CERT_VIGENCIA_SEG,
            'cert_firma_b' => 'CERT-FIRMA-NO-MOSTRAR',
        ]);

        // Un ticket de cada estado: ACTIVO ($500), EXPIRADO ($50) y GASTADO ($300, en un canje).
        $this->ticket(500, $this->ahora + Ticket::VIGENCIA_SEG);
        $this->ticket(50, $this->ahora - 10);
        $gastado = $this->ticket(300, $this->ahora + Ticket::VIGENCIA_SEG);

        Canje::create([
            'id_pedido' => self::PEDIDO, 'id_cuenta' => $this->cuenta->id, 'monto' => 300,
            'momento' => $this->ahora, 'firma_b' => 'FIRMA-CANJE-NO-MOSTRAR',
        ]);
        $this->assertTrue(Ticket::gastar([$gastado->id], self::PEDIDO, $this->cuenta->id, $this->ahora));

        Bitacora::aceptado('/api/login', $this->ahora, $this->cuenta->id, 'Inicio de sesión', '127.0.0.1');
        Bitacora::rechazado('/api/canje', 'TICKET_GASTADO', $this->ahora, $this->cuenta->id, self::XSS, '127.0.0.1');
    }

    private function ticket(int $denominacion, int $expiracion): Ticket
    {
        return Ticket::create([
            'id_cuenta' => $this->cuenta->id, 'denominacion' => $denominacion,
            'emision' => $this->ahora - 100, 'expiracion' => $expiracion, 'firma_b' => 'FIRMA-TICKET-NO-MOSTRAR',
        ]);
    }

    public function test_todas_las_paginas_responden(): void
    {
        foreach (self::PAGINAS as $pagina) {
            $this->get($pagina)->assertOk()->assertSee('Solo lectura');
        }
    }

    public function test_ninguna_pagina_muestra_secretos_ni_ids_completos(): void
    {
        foreach (self::PAGINAS as $pagina) {
            $r = $this->get($pagina)->assertOk();
            foreach (self::SECRETOS as $secreto) {
                $r->assertDontSee($secreto, false);
            }
            $r->assertDontSee($this->cuenta->id, false);
            $r->assertDontSee(self::PEDIDO, false);
        }
    }

    public function test_el_panel_es_de_solo_lectura(): void
    {
        foreach (self::PAGINAS as $pagina) {
            $this->post($pagina)->assertStatus(405);
            $this->delete($pagina)->assertStatus(405);
        }

        $antes = $this->conteos();
        foreach (self::PAGINAS as $pagina) {
            $this->get($pagina)->assertOk();
        }
        $this->assertSame($antes, $this->conteos(), 'ver el panel no cambia la base');
    }

    public function test_la_bitacora_escapa_el_texto_que_viene_de_la_red(): void
    {
        $this->get('/panel/bitacora')
            ->assertOk()
            ->assertDontSee(self::XSS, false)
            ->assertSee(e(self::XSS), false);
    }

    public function test_la_bitacora_filtra_por_resultado_y_codigo(): void
    {
        $this->get('/panel/bitacora?resultado=RECHAZADO')
            ->assertViewHas('filas', fn ($p) => $p->total() === 1 && $p->first()->codigo === 'TICKET_GASTADO');

        $this->get('/panel/bitacora?codigo=TICKET_GASTADO')
            ->assertViewHas('filas', fn ($p) => $p->total() === 1);

        $this->get('/panel/bitacora?cuenta=' . substr($this->cuenta->id, 0, 8))
            ->assertViewHas('filas', fn ($p) => $p->total() === 2);
    }

    public function test_un_filtro_desconocido_o_malicioso_se_ignora(): void
    {
        $this->get("/panel/bitacora?resultado=BORRAR&codigo[]=x&cuenta=%27%3B%20DROP%20TABLE%20cuentas")
            ->assertOk()
            ->assertViewHas('filas', fn ($p) => $p->total() === 2);

        $this->get('/panel/tickets?estado=INVENTADO&denominacion=999')
            ->assertOk()
            ->assertViewHas('tickets', fn ($p) => $p->total() === 3);
    }

    public function test_los_tickets_muestran_su_estado_efectivo(): void
    {
        $denominacionCon = fn (string $estado) => $this->get('/panel/tickets?estado=' . $estado)
            ->assertOk()
            ->viewData('tickets')
            ->pluck('denominacion')
            ->all();

        $this->assertSame([500], $denominacionCon('ACTIVO'));
        $this->assertSame([50], $denominacionCon('EXPIRADO'));
        $this->assertSame([300], $denominacionCon('GASTADO'));

        $this->get('/panel/tickets?denominacion=50')
            ->assertViewHas('resumen', fn ($r) => $r->count() === 1 && (int) $r->first()->expirados === 1);
    }

    public function test_los_canjes_muestran_sus_tickets(): void
    {
        $this->get('/panel/canjes')
            ->assertOk()
            ->assertSee(substr(self::PEDIDO, 0, 8))
            ->assertViewHas('canjes', fn ($p) => $p->total() === 1 && $p->first()->tickets->pluck('denominacion')->all() === [300]);
    }

    public function test_el_inicio_resume_cuentas_tickets_y_rechazos(): void
    {
        $this->get('/panel')
            ->assertOk()
            ->assertViewHas('cuentas', ['total' => 1, 'bloqueadas' => 0])
            ->assertViewHas('tickets', ['activos' => 1, 'gastados' => 1, 'expirados' => 1, 'valor_activo' => 500])
            ->assertViewHas('canjes', ['total' => 1, 'monto' => 300])
            ->assertViewHas('bitacora', ['aceptados' => 1, 'rechazados' => 1])
            ->assertSee('TICKET_GASTADO');
    }

    /** @return array<string,int> */
    private function conteos(): array
    {
        $conteos = [];
        foreach (['cuentas', 'tickets', 'canjes', 'bitacora', 'nonces'] as $tabla) {
            $conteos[$tabla] = DB::table($tabla)->count();
        }
        $conteos['saldo'] = (int) Cuenta::query()->sum('saldo');
        $conteos['gastados'] = Ticket::query()->where('estado', Ticket::GASTADO)->count();

        return $conteos;
    }
}
