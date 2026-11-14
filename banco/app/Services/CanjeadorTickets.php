<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Canje;
use App\Models\Cuenta;
use App\Models\Ticket;
use App\Support\ApiError;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Canje de tickets por un pedido de la tienda (Fase 8). Es la pieza que impide el doble gasto (E2):
 * el canje y el cambio de estado de TODOS los tickets ocurren en una sola transacción, y el cambio de
 * estado es un UPDATE condicionado (solo tickets ACTIVOS, vigentes y de la cuenta). Se gastan todos o ninguno.
 */
class CanjeadorTickets
{
    public function __construct(private readonly CryptoService $cripto)
    {
    }

    /**
     * Dueña de los tickets. Si falta alguno (no es un ticket emitido por este banco) o no son todos de
     * la misma cuenta, el canje no es válido.
     * @param string[] $ids
     * @throws ApiError TICKET_INVALIDO
     */
    public function propietario(array $ids): Cuenta
    {
        $tickets = Ticket::query()->whereIn('id', $ids)->get();
        if (count($tickets) !== count($ids)) {
            throw new ApiError('TICKET_INVALIDO');
        }
        $duenos = [];
        foreach ($tickets as $t) {
            $duenos[$t->id_cuenta] = true;
        }
        if (count($duenos) !== 1) {
            throw new ApiError('TICKET_INVALIDO');
        }

        return Cuenta::find(array_key_first($duenos)) ?? throw new ApiError('TICKET_INVALIDO');
    }

    /**
     * @param string[] $ids  UUID distintos
     * @return array{id_pedido:string,monto:int,ids:string[],timestamp:int,firma_b:string}
     * @throws ApiError TICKET_GASTADO, TICKET_EXPIRADO, MONTO_INCORRECTO, PEDIDO_DUPLICADO
     */
    public function canjear(string $idPedido, int $monto, array $ids, Cuenta $cuenta, int $ahora): array
    {
        sort($ids, SORT_STRING);

        // Idempotencia: el mismo pedido con los mismos tickets devuelve el mismo recibo (aunque los tickets ya estén GASTADOS).
        $previo = Canje::find($idPedido);
        if ($previo !== null) {
            return $this->reciboRepetido($previo, $monto, $ids);
        }

        // Reglas de negocio (contrato, sección 13, paso 8): estado de los tickets y después la suma.
        $suma = 0;
        $gastado = false;
        $vencido = false;
        foreach (Ticket::query()->whereIn('id', $ids)->get() as $t) {
            $estado = $t->estadoEfectivo($ahora);
            $gastado = $gastado || $estado === Ticket::GASTADO;
            $vencido = $vencido || $estado === Ticket::EXPIRADO;
            $suma += $t->denominacion;
        }
        if ($gastado) {
            throw new ApiError('TICKET_GASTADO');
        }
        if ($vencido) {
            throw new ApiError('TICKET_EXPIRADO');
        }
        if ($suma !== $monto) {
            throw new ApiError('MONTO_INCORRECTO');   // no hay cambio: la suma debe ser exacta
        }

        $firma = $this->cripto->firmar(Canonico::reciboCanje($idPedido, $monto, $ids, $ahora));

        try {
            DB::transaction(function () use ($idPedido, $monto, $ids, $cuenta, $ahora, $firma): void {
                Canje::create(['id_pedido' => $idPedido, 'id_cuenta' => $cuenta->id, 'monto' => $monto, 'momento' => $ahora, 'firma_b' => $firma]);
                // Gasta todos o ninguno. Si otra petición ganó la carrera por algún ticket, se deshace también el canje.
                if (!$this->gastar($ids, $idPedido, $cuenta->id, $ahora)) {
                    throw new ApiError('TICKET_GASTADO');
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Dos canjes del MISMO pedido a la vez: el segundo pierde la carrera y recibe el resultado del primero.
            $previo = Canje::find($idPedido) ?? throw new ApiError('ERROR_INTERNO');

            return $this->reciboRepetido($previo, $monto, $ids);
        }

        return ['id_pedido' => $idPedido, 'monto' => $monto, 'ids' => $ids, 'timestamp' => $ahora, 'firma_b' => $firma];
    }

    /** Cambio de estado atómico de los tickets (un solo UPDATE condicionado). Aparte para poder simular carreras en las pruebas. */
    protected function gastar(array $ids, string $idPedido, string $idCuenta, int $ahora): bool
    {
        return Ticket::gastar($ids, $idPedido, $idCuenta, $ahora);
    }

    /** Recibo guardado de un canje (el mismo, con su hora y firma originales). */
    public function recibo(Canje $canje): array
    {
        return [
            'id_pedido' => $canje->id_pedido, 'monto' => (int) $canje->monto, 'ids' => $canje->idsOrdenados(),
            'timestamp' => (int) $canje->momento, 'firma_b' => $canje->firma_b,
        ];
    }

    /** @throws ApiError PEDIDO_DUPLICADO si el pedido ya se canjeó con otros tickets u otro monto */
    private function reciboRepetido(Canje $previo, int $monto, array $ids): array
    {
        if ((int) $previo->monto !== $monto || $previo->idsOrdenados() !== $ids) {
            throw new ApiError('PEDIDO_DUPLICADO');
        }

        return $this->recibo($previo);
    }
}
