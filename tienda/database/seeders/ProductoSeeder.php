<?php

namespace Database\Seeders;

use App\Models\Producto;
use Illuminate\Database\Seeder;

/** Catálogo de prueba (contrato, sección 16, decisión 3). Se puede ejecutar varias veces: actualiza en vez de duplicar. */
class ProductoSeeder extends Seeder
{
    /** id => [nombre, precio]. Todos los precios se pueden pagar con tickets de 500, 300, 100, 50 y 20. */
    public const CATALOGO = [
        1  => ['Taza de cerámica', 370],
        2  => ['Playera estampada', 230],
        3  => ['Libreta de apuntes', 60],
        4  => ['Mochila urbana', 480],
        5  => ['Audífonos con cable', 310],
        6  => ['Termo de acero', 290],
        7  => ['Gorra negra', 150],
        8  => ['Cargador USB', 120],
        9  => ['Llavero metálico', 20],
        10 => ['Memoria USB 32 GB', 180],
        11 => ['Mouse inalámbrico', 260],
        12 => ['Teclado compacto', 640],
    ];

    public function run(): void
    {
        foreach (self::CATALOGO as $id => [$nombre, $precio]) {
            Producto::updateOrCreate(['id' => $id], ['nombre' => $nombre, 'precio' => $precio, 'activo' => true]);
        }
    }
}
