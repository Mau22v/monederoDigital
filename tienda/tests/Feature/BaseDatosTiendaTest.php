<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Nonce;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Producto;
use App\Services\Canonico;
use Database\Seeders\ProductoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;
use Throwable;

/** Fase 7: estructura de la base de datos de la Tienda, reglas de los modelos y semilla del catálogo. */
class BaseDatosTiendaTest extends TestCase
{
    use RefreshDatabase;

    private const T0 = 1762500000;

    private function pedido(array $extra = []): Pedido
    {
        return Pedido::create($extra + [
            'id_cuenta' => '7a1d9e22-0c3b-4f8a-8b11-aa22bb33cc44', 'monto' => 370, 'nonce_t' => str_repeat('a', 32),
            'creado_en' => self::T0, 'expiracion' => self::T0 + 600, 'firma_t' => 'AAAA',
        ]);
    }

    private function lanza(callable $f): bool
    {
        try {
            $f();
        } catch (Throwable) {
            return true;
        }

        return false;
    }

    // ---------------- Estructura ----------------

    public function test_las_migraciones_crean_las_cinco_tablas_de_la_tienda(): void
    {
        foreach (['productos', 'pedidos', 'pedido_items', 'nonces', 'bitacora'] as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "falta la tabla $tabla");
        }
        $this->assertTrue(Schema::hasColumns('pedidos', ['id', 'id_cuenta', 'monto', 'nonce_t', 'creado_en', 'expiracion', 'estado', 'firma_t']));
        $this->assertTrue(Schema::hasColumns('pedido_items', ['id', 'id_pedido', 'id_producto', 'nombre', 'precio_unitario', 'cantidad']));
        $this->assertTrue(Schema::hasColumns('productos', ['id', 'nombre', 'precio', 'activo']));
    }

    // ---------------- Reglas de precios ----------------

    public function test_un_precio_es_valido_si_y_solo_si_se_puede_pagar_con_dinero_exacto(): void
    {
        // Programación dinámica: ¿qué sumas se pueden formar con tickets de 500, 300, 100, 50 y 20?
        $alcanzable = array_fill(0, Producto::PRECIO_MAXIMO + 1, false);
        $alcanzable[0] = true;
        for ($suma = 1; $suma <= Producto::PRECIO_MAXIMO; $suma++) {
            foreach ([500, 300, 100, 50, 20] as $ticket) {
                if ($suma >= $ticket && $alcanzable[$suma - $ticket]) {
                    $alcanzable[$suma] = true;
                    break;
                }
            }
        }

        $diferencias = [];
        for ($precio = 1; $precio <= Producto::PRECIO_MAXIMO + 50; $precio++) {
            $esperado = $precio <= Producto::PRECIO_MAXIMO && $alcanzable[$precio];
            if (Producto::precioValido($precio) !== $esperado) {
                $diferencias[] = $precio;
            }
        }
        $this->assertSame([], $diferencias);
        $this->assertFalse(Producto::precioValido(0));
        $this->assertFalse(Producto::precioValido(-20));
        $this->assertFalse(Producto::precioValido(10));
        $this->assertFalse(Producto::precioValido(30));
        $this->assertTrue(Producto::precioValido(20));
        $this->assertTrue(Producto::precioValido(40));
    }

    public function test_el_nombre_no_puede_traer_caracteres_que_separan_campos_firmados(): void
    {
        foreach (['Taza', 'Libreta ñandú', 'Memoria USB 32 GB', 'A'] as $bien) {
            $this->assertTrue(Producto::nombreValido($bien), $bien);
        }
        foreach (['Taza, grande', 'A|B', 'A:B', 'A;B', ' Taza', 'Taza ', "Sal\nto", '', str_repeat('x', 61)] as $mal) {
            $this->assertFalse(Producto::nombreValido($mal), json_encode($mal));
        }
    }

    public function test_un_producto_invalido_no_llega_a_la_base_de_datos(): void
    {
        $this->assertTrue($this->lanza(fn () => Producto::create(['id' => 50, 'nombre' => 'Caro', 'precio' => 30])));
        $this->assertTrue($this->lanza(fn () => Producto::create(['id' => 51, 'nombre' => 'Con:dos', 'precio' => 50])));
        $this->assertTrue($this->lanza(fn () => Producto::create(['id' => 52, 'nombre' => 'Imposible', 'precio' => 10010])));
        $this->assertSame(0, Producto::count());
        Producto::create(['id' => 53, 'nombre' => 'Bueno', 'precio' => 50]);
        $this->assertSame(1, Producto::count());
        $this->assertTrue($this->lanza(fn () => Producto::find(53)->update(['precio' => 30])));
        $this->assertSame(50, Producto::find(53)->precio);
    }

    // ---------------- Semilla ----------------

    public function test_la_semilla_crea_12_productos_validos_que_se_pueden_firmar(): void
    {
        $this->seed(ProductoSeeder::class);

        $this->assertSame(12, Producto::count());
        $catalogo = Producto::catalogo();
        $this->assertSame(range(1, 12), array_column($catalogo, 'id'));
        foreach ($catalogo as $p) {
            $this->assertTrue(Producto::precioValido($p['precio']), "precio {$p['precio']}");
        }
        $this->assertStringStartsWith('catalogo|v1|' . self::T0 . '|1:Taza de cerámica:370;', Canonico::catalogo(self::T0, $catalogo));
    }

    public function test_ejecutar_la_semilla_dos_veces_no_duplica_y_restaura_los_datos(): void
    {
        $this->seed(ProductoSeeder::class);
        Producto::find(1)->update(['precio' => 400, 'nombre' => 'Otra']);
        $this->seed(ProductoSeeder::class);

        $this->assertSame(12, Producto::count());
        $this->assertSame(370, Producto::find(1)->precio);
        $this->assertSame('Taza de cerámica', Producto::find(1)->nombre);
    }

    // ---------------- Pedidos ----------------

    public function test_el_pedido_recibe_un_uuid_v4_y_estado_creado_por_omision(): void
    {
        $p = $this->pedido();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $p->id);
        $this->assertSame('CREADO', Pedido::find($p->id)->estado);
    }

    public function test_el_estado_efectivo_expira_en_el_instante_de_la_expiracion(): void
    {
        $p = $this->pedido();

        $this->assertSame('CREADO', $p->estadoEfectivo(self::T0));
        $this->assertSame('CREADO', $p->estadoEfectivo(self::T0 + 599));
        $this->assertSame('EXPIRADO', $p->estadoEfectivo(self::T0 + 600));
        $this->assertSame('EXPIRADO', $p->estadoEfectivo(self::T0 + 99999));

        $p->update(['estado' => 'PAGADO']);
        $this->assertSame('PAGADO', $p->estadoEfectivo(self::T0 + 99999));
        $p->update(['estado' => 'RECHAZADO']);
        $this->assertSame('RECHAZADO', $p->estadoEfectivo(self::T0));
    }

    public function test_un_pedido_solo_se_encuentra_para_su_dueno(): void
    {
        $p = $this->pedido();

        $this->assertNotNull(Pedido::delCliente($p->id, $p->id_cuenta));
        $this->assertNull(Pedido::delCliente($p->id, '11111111-1111-4111-8111-111111111111'));
        $this->assertNull(Pedido::delCliente('22222222-2222-4222-8222-222222222222', $p->id_cuenta));
    }

    public function test_la_base_de_datos_impide_pedidos_incompletos_o_con_estado_invalido(): void
    {
        $this->assertTrue($this->lanza(fn () => Pedido::create(['id_cuenta' => 'x', 'monto' => 1, 'nonce_t' => 'n', 'creado_en' => 1, 'expiracion' => 2])));   // sin firma_t
        $this->assertTrue($this->lanza(fn () => $this->pedido(['estado' => 'EXPIRADO'])));   // EXPIRADO no se guarda
        $this->assertSame(0, Pedido::count());
    }

    // ---------------- Renglones ----------------

    public function test_un_producto_no_se_repite_dentro_del_mismo_pedido(): void
    {
        $p = $this->pedido();
        $renglon = ['id_pedido' => $p->id, 'id_producto' => 1, 'nombre' => 'Taza', 'precio_unitario' => 370, 'cantidad' => 1];
        PedidoItem::create($renglon);

        $this->assertTrue($this->lanza(fn () => PedidoItem::create($renglon)));
        $this->assertSame(1, PedidoItem::count());
        PedidoItem::create(['id_producto' => 2] + $renglon);   // otro producto sí
        $this->assertSame(2, PedidoItem::count());
    }

    public function test_los_renglones_no_pueden_apuntar_a_un_pedido_inexistente_y_se_borran_con_su_pedido(): void
    {
        $this->assertTrue($this->lanza(fn () => PedidoItem::create([
            'id_pedido' => '33333333-3333-4333-8333-333333333333', 'id_producto' => 1, 'nombre' => 'Taza', 'precio_unitario' => 370, 'cantidad' => 1,
        ])));

        $p = $this->pedido();
        PedidoItem::create(['id_pedido' => $p->id, 'id_producto' => 1, 'nombre' => 'Taza', 'precio_unitario' => 370, 'cantidad' => 1]);
        $this->assertSame(1, $p->items()->count());
        $p->delete();
        $this->assertSame(0, PedidoItem::count());
    }

    // ---------------- Nonces y bitácora ----------------

    public function test_el_nonce_es_clave_primaria_el_segundo_intento_pierde(): void
    {
        $n = str_repeat('b', 32);

        $this->assertFalse(Nonce::yaUsado($n));
        $this->assertTrue(Nonce::registrar($n, 'cuenta', '/api/pedidos', self::T0));
        $this->assertFalse(Nonce::registrar($n, 'otra', '/api/pedidos', self::T0));
        $this->assertTrue(Nonce::yaUsado($n));
        $this->assertSame(1, Nonce::count());
        $this->assertSame('cuenta', Nonce::first()->origen);
    }

    public function test_los_nonces_se_purgan_cuando_termina_su_retencion(): void
    {
        Nonce::registrar(str_repeat('c', 32), 'x', '/e', self::T0);

        $this->assertSame(0, Nonce::purgar(self::T0 + 899));
        $this->assertSame(1, Nonce::purgar(self::T0 + 900));
        $this->assertSame(0, Nonce::count());
    }

    public function test_la_bitacora_guarda_aceptados_y_rechazados_incluso_de_cuentas_inexistentes(): void
    {
        Bitacora::aceptado('/api/pedidos', self::T0, 'cuenta-que-no-existe', 'ok', '127.0.0.1');
        Bitacora::rechazado('/api/pedidos', 'FIRMA_INVALIDA', self::T0, null, str_repeat('x', 800), '127.0.0.1');

        $this->assertSame(2, Bitacora::count());
        $this->assertSame(500, mb_strlen(Bitacora::where('resultado', 'RECHAZADO')->first()->detalle));   // el detalle se recorta
        $this->assertTrue($this->lanza(fn () => Bitacora::create(['momento' => 1, 'endpoint' => '/x', 'resultado' => 'TAL_VEZ'])));
    }
}
