<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Producto;
use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea un pedido: calcula el monto con los precios DEL SERVIDOR (nunca con un precio que mande el cliente),
 * lo firma con la llave de la tienda y guarda el pedido y sus renglones en una sola transacción.
 */
class CreadorPedidos
{
    public function __construct(private readonly CryptoService $cripto)
    {
    }

    /**
     * @param array<int,array{id:int,cantidad:int}> $items  ya validados
     * @throws ApiError FORMATO_INVALIDO si un producto no existe, está retirado o el monto excede el máximo
     */
    public function crear(string $idCuenta, array $items, int $ahora): Pedido
    {
        $productos = Producto::activosPorId(array_column($items, 'id'));

        $monto = 0;
        $lineas = [];
        foreach ($items as $item) {
            $producto = $productos[$item['id']] ?? throw new ApiError('FORMATO_INVALIDO', 'Un producto no existe o ya no está disponible');
            $monto += $producto->precio * $item['cantidad'];
            $lineas[] = [
                'id_producto' => $producto->id, 'nombre' => $producto->nombre,
                'precio_unitario' => $producto->precio, 'cantidad' => $item['cantidad'],
            ];
        }
        if ($monto > Pedido::MONTO_MAXIMO) {
            throw new ApiError('FORMATO_INVALIDO', 'El monto del pedido excede el máximo permitido');
        }

        $id = (string) Str::uuid();
        $nonceT = $this->cripto->nonce();
        $expiracion = $ahora + Pedido::VIGENCIA_SEG;
        $firma = $this->cripto->firmar(Canonico::pedido($id, $idCuenta, $monto, $nonceT, $expiracion));

        return DB::transaction(function () use ($id, $idCuenta, $monto, $nonceT, $ahora, $expiracion, $firma, $lineas): Pedido {
            $pedido = Pedido::create([
                'id' => $id, 'id_cuenta' => $idCuenta, 'monto' => $monto, 'nonce_t' => $nonceT,
                'creado_en' => $ahora, 'expiracion' => $expiracion, 'estado' => Pedido::CREADO, 'firma_t' => $firma,
            ]);
            foreach ($lineas as $linea) {
                PedidoItem::create($linea + ['id_pedido' => $id]);
            }

            return $pedido;
        });
    }
}
