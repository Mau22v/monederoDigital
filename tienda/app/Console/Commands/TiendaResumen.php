<?php

namespace App\Console\Commands;

use App\Models\Pedido;
use App\Models\Producto;
use Illuminate\Console\Command;

/** Muestra el catálogo y los pedidos de la tienda, para ver el efecto de las operaciones en la base de datos. */
class TiendaResumen extends Command
{
    protected $signature = 'tienda:resumen';
    protected $description = 'Muestra los productos y los pedidos de la tienda';

    public function handle(): int
    {
        $this->info('PRODUCTOS');
        $this->table(
            ['id', 'nombre', 'precio', 'activo'],
            Producto::orderBy('id')->get()->map(fn ($p) => [$p->id, $p->nombre, '$' . $p->precio, $p->activo ? 'sí' : 'no'])->all()
        );

        $this->info('PEDIDOS (los más recientes primero)');
        $ahora = time();
        $this->table(
            ['pedido', 'cuenta', 'monto', 'estado', 'renglones', 'vence'],
            Pedido::orderByDesc('creado_en')->limit(20)->get()->map(fn ($p) => [
                substr($p->id, 0, 8) . '…', substr($p->id_cuenta, 0, 8) . '…', '$' . $p->monto,
                $p->estadoEfectivo($ahora), $p->items()->count(), date('H:i:s', $p->expiracion),
            ])->all()
        );

        return self::SUCCESS;
    }
}
