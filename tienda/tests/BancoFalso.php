<?php

namespace Tests;

use App\Models\Pedido;
use App\Services\BancoCliente;
use App\Services\BancoNoDisponible;
use App\Services\BancoRechazo;
use App\Services\Canonico;
use App\Services\CryptoService;
use Closure;

/**
 * Banco de mentira para las pruebas de la Tienda. Recuerda los canjes y los tickets gastados como el banco real
 * (la prueba con los DOS servidores de verdad está en el cliente:demo y en la Fase 10) y permite provocar fallas.
 */
class BancoFalso extends BancoCliente
{
    /** @var array<string,array> id_pedido => recibo */
    public array $canjes = [];
    /** @var array<string,string> id de ticket => id_pedido */
    public array $gastados = [];
    /** @var array<int,array> llamadas recibidas: ['canjear'|'consultar', id_pedido] */
    public array $llamadas = [];
    public bool $sinRed = false;              // el banco no responde y NO ejecuta nada
    public bool $perderRespuesta = false;     // el banco ejecuta el canje pero la respuesta no llega
    public ?string $rechazo = null;           // el banco responde con este código de error
    public ?array $reciboAlterado = null;     // campos que se cambian en el recibo DESPUÉS de firmarlo
    public ?Closure $alCanjear = null;        // se ejecuta justo antes de devolver el recibo (simula una petición simultánea)
    public ?array $ultimoCanje = null;        // lo último que recibió canjear(): monto, ids, orden

    public function __construct(private readonly CryptoService $firmaBanco)
    {
    }

    public function canjear(string $idPedido, int $monto, array $ids, array $orden, int $ahora): array
    {
        sort($ids, SORT_STRING);
        $this->llamadas[] = ['canjear', $idPedido];
        $this->ultimoCanje = ['monto' => $monto, 'ids' => $ids, 'orden' => $orden];
        if ($this->sinRed) {
            throw new BancoNoDisponible('sin red');
        }
        if ($this->rechazo !== null) {
            throw new BancoRechazo($this->rechazo);
        }
        if (isset($this->canjes[$idPedido])) {
            $previo = $this->canjes[$idPedido];
            if ($previo['monto'] !== $monto || $previo['ids'] !== $ids) {
                throw new BancoRechazo('PEDIDO_DUPLICADO');
            }

            return $previo;
        }
        foreach ($ids as $id) {
            if (isset($this->gastados[$id])) {
                throw new BancoRechazo('TICKET_GASTADO');
            }
        }
        $recibo = [
            'id_pedido' => $idPedido, 'monto' => $monto, 'ids' => $ids, 'timestamp' => $ahora,
            'firma_b' => $this->firmaBanco->firmar(Canonico::reciboCanje($idPedido, $monto, $ids, $ahora)),
        ];
        $this->canjes[$idPedido] = $recibo;
        foreach ($ids as $id) {
            $this->gastados[$id] = $idPedido;
        }
        if ($this->alCanjear !== null) {
            ($this->alCanjear)($idPedido);
        }
        if ($this->perderRespuesta) {
            throw new BancoNoDisponible('respuesta perdida');
        }

        return array_merge($recibo, $this->reciboAlterado ?? []);
    }

    public function consultar(string $idPedido, int $ahora): array
    {
        $this->llamadas[] = ['consultar', $idPedido];
        if ($this->sinRed) {
            throw new BancoNoDisponible('sin red');
        }
        if (!isset($this->canjes[$idPedido])) {
            throw new BancoRechazo('CANJE_NO_ENCONTRADO');
        }

        return $this->canjes[$idPedido];
    }

    public function veces(string $tipo): int
    {
        return count(array_filter($this->llamadas, fn ($l) => $l[0] === $tipo));
    }

    /** Hace como si el banco ya hubiera canjeado ESTE pedido con estos tickets (la tienda no se enteró). */
    public function canjeoPorSuCuenta(string $idPedido, int $monto, array $ids, int $ahora): void
    {
        sort($ids, SORT_STRING);
        $this->canjes[$idPedido] = [
            'id_pedido' => $idPedido, 'monto' => $monto, 'ids' => $ids, 'timestamp' => $ahora,
            'firma_b' => $this->firmaBanco->firmar(Canonico::reciboCanje($idPedido, $monto, $ids, $ahora)),
        ];
        foreach ($ids as $id) {
            $this->gastados[$id] = $idPedido;
        }
    }
}
