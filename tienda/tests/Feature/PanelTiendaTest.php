<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 9: panel web de solo lectura de la tienda.
 * Comprueba que las páginas respondan, que los filtros y el estado efectivo funcionen,
 * que el panel no escriba y que nunca muestre firmas, nonces ni ids completos
 * (docs/Guia_Paneles_Web.md, sección 4). Usan SQLite en memoria (phpunit.xml).
 */
class PanelTiendaTest extends TestCase
{
    use RefreshDatabase;

    private const CUENTA = '7a1d9e22-0c3b-4f8a-8b11-aa22bb33cc44';
    private const PAGADO = '11111111-1111-4111-8111-111111111111';
    private const CREADO = '22222222-2222-4222-8222-222222222222';
    private const EXPIRADO = '33333333-3333-4333-8333-333333333333';
    private const TICKET_1 = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const TICKET_2 = 'bbbbbbbb-0000-4000-8000-000000000002';
    private const TICKET_3 = 'cccccccc-0000-4000-8000-000000000003';
    private const XSS = '<script>alert("xss")</script>';

    /** Valores que jamás deben aparecer en el HTML del panel. */
    private const SECRETOS = ['FIRMA-T-NO-MOSTRAR', 'NONCE-T-NO-MOSTRAR', 'FIRMA-COMPROBANTE-NO-MOSTRAR', 'RECIBO-BANCO-NO-MOSTRAR'];

    private const PAGINAS = [
        '/panel', '/panel/bitacora', '/panel/productos', '/panel/pedidos',
        '/panel/pedidos/11111111', '/panel/pedidos/22222222',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $ahora = time();

        Producto::create(['id' => 1, 'nombre' => 'Taza de cerámica', 'precio' => 370, 'activo' => true]);
        Producto::create(['id' => 3, 'nombre' => 'Libreta de apuntes', 'precio' => 60, 'activo' => true]);
        Producto::create(['id' => 9, 'nombre' => 'Llavero metálico', 'precio' => 20, 'activo' => false]);

        // PAGADO: Taza x1 + Libreta x2 = 490, con comprobante y recibo del banco.
        $this->pedido(self::PAGADO, 490, $ahora + 600, [[1, 'Taza de cerámica', 370, 1], [3, 'Libreta de apuntes', 60, 2]]);
        $pagado = Pedido::find(self::PAGADO);
        $this->assertTrue($pagado->marcarPagado([self::TICKET_1, self::TICKET_2], $ahora, 'FIRMA-COMPROBANTE-NO-MOSTRAR', $ahora, 'RECIBO-BANCO-NO-MOSTRAR'));

        // CREADO y vigente, con un intento de pago anotado (pago en curso).
        $this->pedido(self::CREADO, 60, $ahora + 600, [[3, 'Libreta de apuntes', 60, 1]]);
        Pedido::find(self::CREADO)->registrarIntento([self::TICKET_3]);

        // CREADO pero vencido: el panel debe mostrarlo como EXPIRADO.
        $this->pedido(self::EXPIRADO, 20, $ahora - 1, [[9, 'Llavero metálico', 20, 1]]);

        Bitacora::aceptado('/api/pedidos', $ahora, self::CUENTA, 'Pedido 11111111 por $490', '127.0.0.1');
        Bitacora::rechazado('/api/pedidos/{id}/pago', 'TICKET_INVALIDO', $ahora, self::CUENTA, self::XSS, '127.0.0.1');
        Bitacora::rechazado('/api/pedidos', 'FIRMA_INVALIDA', $ahora, self::CUENTA, 'La firma no es válida', '127.0.0.1');
    }

    /** @param array<int,array{0:int,1:string,2:int,3:int}> $renglones [id_producto, nombre, precio, cantidad] */
    private function pedido(string $id, int $monto, int $expiracion, array $renglones): void
    {
        Pedido::create([
            'id' => $id, 'id_cuenta' => self::CUENTA, 'monto' => $monto,
            'nonce_t' => str_pad('NONCE-T-NO-MOSTRAR', 32, 'x'),
            'creado_en' => $expiracion - Pedido::VIGENCIA_SEG, 'expiracion' => $expiracion,
            'firma_t' => 'FIRMA-T-NO-MOSTRAR',
        ]);
        foreach ($renglones as [$producto, $nombre, $precio, $cantidad]) {
            PedidoItem::create(['id_pedido' => $id, 'id_producto' => $producto, 'nombre' => $nombre, 'precio_unitario' => $precio, 'cantidad' => $cantidad]);
        }
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
            foreach ([self::CUENTA, self::PAGADO, self::CREADO, self::EXPIRADO, self::TICKET_1, self::TICKET_2, self::TICKET_3] as $id) {
                $r->assertDontSee($id, false);
            }
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

    public function test_la_bitacora_filtra_y_marca_el_escenario_de_ataque(): void
    {
        $this->get('/panel/bitacora?resultado=RECHAZADO')
            ->assertViewHas('filas', fn ($p) => $p->total() === 2);

        $this->get('/panel/bitacora?codigo=TICKET_INVALIDO')
            ->assertViewHas('filas', fn ($p) => $p->total() === 1)
            ->assertSee('<span class="badge text-bg-dark">E1</span>', false)
            ->assertDontSee('<span class="badge text-bg-dark">E3</span>', false);

        $this->get('/panel/bitacora?codigo=FIRMA_INVALIDA')
            ->assertSee('<span class="badge text-bg-dark">E3</span>', false);
    }

    public function test_un_filtro_desconocido_o_malicioso_se_ignora(): void
    {
        $this->get('/panel/bitacora?resultado=BORRAR&codigo[]=x&cuenta=%27%3B%20DROP%20TABLE%20pedidos')
            ->assertOk()
            ->assertViewHas('filas', fn ($p) => $p->total() === 3);

        $this->get('/panel/pedidos?estado=INVENTADO&pedido=%3Cscript%3E')
            ->assertOk()
            ->assertViewHas('pedidos', fn ($p) => $p->total() === 3);
    }

    public function test_los_pedidos_muestran_su_estado_efectivo(): void
    {
        $idsCon = fn (string $estado) => $this->get('/panel/pedidos?estado=' . $estado)
            ->assertOk()
            ->viewData('pedidos')
            ->pluck('id')
            ->all();

        $this->assertSame([self::PAGADO], $idsCon('PAGADO'));
        $this->assertSame([self::CREADO], $idsCon('CREADO'));
        $this->assertSame([self::EXPIRADO], $idsCon('EXPIRADO'));

        $this->get('/panel/pedidos')->assertSee('pago en curso');
    }

    public function test_el_detalle_muestra_renglones_tickets_y_si_existen_las_firmas(): void
    {
        $this->get('/panel/pedidos/11111111')
            ->assertOk()
            ->assertSee('Taza de cerámica')
            ->assertSee('Libreta de apuntes')
            ->assertSee('$490')
            ->assertSee('aaaaaaaa')
            ->assertSee('bbbbbbbb')
            ->assertSee('/panel/canjes?pedido=11111111', false)
            ->assertViewHas('items', fn ($items) => $items->count() === 2)
            ->assertViewHas('pedido', fn ($p) => (bool) $p->tiene_firma_t && (bool) $p->tiene_comprobante && (bool) $p->tiene_recibo);

        $this->get('/panel/pedidos/22222222')
            ->assertOk()
            ->assertSee('pago en curso')
            ->assertSee('cccccccc')
            ->assertViewHas('pedido', fn ($p) => !$p->tiene_comprobante && !$p->tiene_recibo);
    }

    public function test_un_pedido_inexistente_o_un_id_mal_formado_da_404(): void
    {
        $this->get('/panel/pedidos/99999999')->assertNotFound();
        $this->get('/panel/pedidos/ABC')->assertNotFound();
        $this->get('/panel/pedidos/' . self::PAGADO)->assertNotFound();   // solo se acepta el prefijo de 8
    }

    public function test_los_productos_muestran_ventas_solo_de_pedidos_pagados(): void
    {
        $this->get('/panel/productos')
            ->assertOk()
            ->assertSee('RETIRADO')
            ->assertViewHas('productos', fn ($p) => $p->total() === 3)
            ->assertViewHas('ventas', fn ($v) => (int) $v->get(1)->unidades === 1
                && (int) $v->get(3)->unidades === 2                 // la Libreta del pedido CREADO no cuenta
                && (int) $v->get(3)->ingresos === 120
                && $v->get(9) === null);
    }

    public function test_el_inicio_resume_productos_pedidos_y_rechazos(): void
    {
        $this->get('/panel')
            ->assertOk()
            ->assertViewHas('productos', ['activos' => 2, 'total' => 3])
            ->assertViewHas('pedidos', ['total' => 3, 'creados' => 1, 'pagados' => 1, 'expirados' => 1, 'ingresos' => 490])
            ->assertViewHas('bitacora', ['aceptados' => 1, 'rechazados' => 2])
            ->assertSee('TICKET_INVALIDO')
            ->assertSee('FIRMA_INVALIDA');
    }

    /** @return array<string,int> */
    private function conteos(): array
    {
        $conteos = [];
        foreach (['productos', 'pedidos', 'pedido_items', 'bitacora', 'nonces'] as $tabla) {
            $conteos[$tabla] = DB::table($tabla)->count();
        }
        $conteos['pagados'] = Pedido::query()->where('estado', Pedido::PAGADO)->count();
        $conteos['intentos'] = Pedido::query()->whereNotNull('ids_tickets')->count();

        return $conteos;
    }
}
