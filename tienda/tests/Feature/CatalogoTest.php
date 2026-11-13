<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Producto;
use App\Services\Canonico;
use App\Services\CryptoService;
use Tests\TiendaTestCase;

/** Fase 7: GET /api/catalogo. Público, sin sobre y firmado por la tienda. */
class CatalogoTest extends TiendaTestCase
{
    private function pedirCatalogo(): array
    {
        $r = $this->getJson('/api/catalogo');
        $r->assertStatus(200);

        return $r->json();
    }

    private function firmaValida(array $c): bool
    {
        return $this->cliente->verificar(Canonico::catalogo($c['timestamp'], $c['productos']), $c['firma_t'], $this->pem('tienda_ec'));
    }

    public function test_el_catalogo_es_publico_sin_sobre_y_trae_los_12_productos(): void
    {
        $r = $this->getJson('/api/catalogo');

        $r->assertStatus(200)->assertJsonStructure(['timestamp', 'productos', 'firma_t'])->assertJsonMissingPath('iv')->assertJsonMissingPath('ct');
        $c = $r->json();
        $this->assertSame(self::T0, $c['timestamp']);
        $this->assertCount(12, $c['productos']);
        $this->assertSame(['id' => 1, 'nombre' => 'Taza de cerámica', 'precio' => 370], $c['productos'][0]);
        $this->assertSame(range(1, 12), array_column($c['productos'], 'id'));
    }

    public function test_la_firma_de_la_tienda_verifica_con_su_llave_publica(): void
    {
        $this->assertTrue($this->firmaValida($this->pedirCatalogo()));
    }

    public function test_los_nombres_con_acentos_verifican_despues_de_viajar_en_json(): void
    {
        $c = $this->pedirCatalogo();
        $this->assertSame('Audífonos con cable', $c['productos'][4]['nombre']);
        $this->assertTrue($this->firmaValida($c));
    }

    public function test_cualquier_alteracion_del_catalogo_rompe_la_firma(): void
    {
        $c = $this->pedirCatalogo();

        $precio = $c; $precio['productos'][0]['precio'] = 1;
        $this->assertFalse($this->firmaValida($precio));

        $nombre = $c; $nombre['productos'][1]['nombre'] = 'Playera gratis';
        $this->assertFalse($this->firmaValida($nombre));

        $falta = $c; array_pop($falta['productos']);
        $this->assertFalse($this->firmaValida($falta));

        $extra = $c; $extra['productos'][] = ['id' => 13, 'nombre' => 'Falso', 'precio' => 20];
        $this->assertFalse($this->firmaValida($extra));

        $hora = $c; $hora['timestamp'] += 1;
        $this->assertFalse($this->firmaValida($hora));

    }

    public function test_el_catalogo_firmado_por_otra_llave_no_verifica_con_la_llave_de_la_tienda(): void
    {
        $c = $this->pedirCatalogo();
        $c['firma_t'] = $this->atacante->firmar(Canonico::catalogo($c['timestamp'], $c['productos']));

        $this->assertFalse($this->firmaValida($c));
    }

    public function test_los_productos_retirados_no_aparecen_y_la_firma_sigue_valida(): void
    {
        Producto::find(5)->update(['activo' => false]);

        $c = $this->pedirCatalogo();
        $this->assertCount(11, $c['productos']);
        $this->assertFalse(in_array(5, array_column($c['productos'], 'id'), true));
        $this->assertTrue($this->firmaValida($c));
    }

    public function test_un_catalogo_vacio_tambien_se_firma(): void
    {
        Producto::query()->update(['activo' => false]);

        $c = $this->pedirCatalogo();
        $this->assertSame([], $c['productos']);
        $this->assertTrue($this->firmaValida($c));
    }

    public function test_cada_producto_trae_solo_id_nombre_y_precio(): void
    {
        foreach ($this->pedirCatalogo()['productos'] as $p) {
            $this->assertSame(['id', 'nombre', 'precio'], array_keys($p));
            $this->assertTrue(is_int($p['id']) && is_int($p['precio']));
        }
    }

    public function test_cada_consulta_queda_en_la_bitacora(): void
    {
        $this->pedirCatalogo();

        $fila = Bitacora::orderByDesc('id')->first();
        $this->assertSame('/api/catalogo', $fila->endpoint);
        $this->assertSame('ACEPTADO', $fila->resultado);
        $this->assertSame('12 productos', $fila->detalle);
    }

    public function test_si_la_tienda_no_puede_firmar_responde_error_interno_sin_detalles(): void
    {
        $this->app->instance(CryptoService::class, new CryptoService(null, null));   // sin llave EC

        $r = $this->getJson('/api/catalogo');

        $r->assertStatus(500)->assertJsonPath('error.codigo', 'ERROR_INTERNO');
        $this->assertStringNotContainsString('llave', $r->getContent());
        $this->assertSame('RECHAZADO', Bitacora::orderByDesc('id')->first()->resultado);
    }
}
