<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Pedido;
use App\Support\ApiError;
use Throwable;

/**
 * Pago de un pedido con tickets (Fase 8): la tienda pide al banco el canje, comprueba que el recibo sea auténtico
 * y SOLO entonces marca el pedido como PAGADO y firma el comprobante.
 *
 * Reglas que mantienen la consistencia aunque se corte la red:
 *  - Antes de llamar al banco se anotan los tickets del intento. Si la respuesta se pierde, el pedido sigue CREADO
 *    pero con intento pendiente: la siguiente consulta de estado (o un nuevo pago) pregunta al banco y concilia.
 *  - El canje es idempotente en el banco (mismo pedido y mismos tickets = mismo recibo), así que reintentar es seguro.
 *  - Un pedido PAGADO nunca vuelve atrás y se paga una sola vez (cambio de estado condicionado en la base de datos).
 */
class ProcesadorPagos
{
    /** Errores del banco que se informan tal cual al cliente: la operación no se ejecutó y la causa es suya. */
    private const ERRORES_PARA_EL_CLIENTE = ['TICKET_GASTADO', 'TICKET_EXPIRADO', 'TICKET_INVALIDO', 'MONTO_INCORRECTO', 'PEDIDO_DUPLICADO', 'CERTIFICADO_INVALIDO'];

    public function __construct(
        private readonly CryptoService $cripto,
        private readonly BancoCliente $banco,
        private readonly CertificadoUsuario $verificador,
    ) {
    }

    /**
     * @param array $orden    la orden del cliente (id_pedido, monto, tickets, nonce, timestamp), ya validada y con firma_u verificada
     * @param array $tickets  los tickets de la orden, ya con la firma del banco verificada
     * @return array{0:array,1:array} [comprobante, recibo_canje]
     * @throws ApiError
     */
    public function pagar(Pedido $pedido, array $orden, string $firmaU, array $tickets, int $ahora): array
    {
        $ids = array_column($tickets, 'id');
        sort($ids, SORT_STRING);

        // 1. ¿Un intento anterior ya se ejecutó en el banco aunque no lo supimos? Se concilia primero.
        $this->conciliar($pedido, $ahora);

        // 2. Ya pagado: mismo pago = misma respuesta; cualquier otro intento es un duplicado.
        if ($pedido->estado === Pedido::PAGADO) {
            if ($pedido->idsTickets() !== $ids || (int) $pedido->monto !== $orden['monto']) {
                throw new ApiError('PEDIDO_DUPLICADO');
            }

            return $this->respuesta($pedido);
        }

        // 3. Reglas de negocio de la tienda.
        if ($ahora >= $pedido->expiracion) {
            throw new ApiError('PEDIDO_EXPIRADO');
        }
        if ($orden['monto'] !== (int) $pedido->monto) {
            throw new ApiError('MONTO_INCORRECTO');
        }
        $suma = 0;
        foreach ($tickets as $t) {
            $suma += $t['denominacion'];
        }
        if ($suma !== (int) $pedido->monto) {
            throw new ApiError('MONTO_INCORRECTO');   // sin cambio: la suma debe ser exacta
        }
        foreach ($tickets as $t) {
            if ($ahora >= $t['expiracion']) {
                throw new ApiError('TICKET_EXPIRADO');
            }
        }

        // 4. Canje en el banco, con el intento ya anotado.
        $pedido->registrarIntento($ids);
        try {
            $recibo = $this->banco->canjear($pedido->id, (int) $pedido->monto, $ids, ['nonce' => $orden['nonce'], 'timestamp' => $orden['timestamp'], 'firma_u' => $firmaU], $ahora);
        } catch (BancoRechazo $e) {
            if ($e->codigo === 'PEDIDO_DUPLICADO') {
                $this->conciliar($pedido, $ahora);   // el banco ya canjeó este pedido con otros tickets: queda PAGADO si es así
                throw new ApiError('PEDIDO_DUPLICADO');
            }
            $pedido->limpiarIntento();                // el banco dijo que NO: no hay nada pendiente
            if (in_array($e->codigo, self::ERRORES_PARA_EL_CLIENTE, true)) {
                throw new ApiError($e->codigo);
            }
            throw $e;                                // cualquier otro código del banco es un fallo nuestro: ERROR_INTERNO
        }
        // BancoNoDisponible sube tal cual (ERROR_INTERNO): el intento queda anotado para conciliar.

        // 5. Solo con un recibo auténtico y que corresponde a ESTE pedido se cobra.
        $this->exigirReciboValido($pedido, $recibo, $ids);
        $this->cobrar($pedido, $recibo, $ahora);

        return $this->respuesta($pedido);
    }

    /**
     * Si el pedido tiene un intento pendiente, pregunta al banco si ya lo canjeó y, de ser así, lo marca PAGADO.
     * Nunca falla: si el banco no responde simplemente no concilia (se reintentará en la siguiente consulta).
     */
    public function conciliar(Pedido $pedido, int $ahora): void
    {
        if (!$pedido->tieneIntento()) {
            return;
        }
        try {
            $recibo = $this->banco->consultar($pedido->id, $ahora);
            $this->exigirReciboValido($pedido, $recibo, null);
            $this->cobrar($pedido, $recibo, $ahora);
        } catch (BancoRechazo $e) {
            if ($e->codigo !== 'CANJE_NO_ENCONTRADO') {
                report($e);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Comprobante y recibo guardados de un pedido PAGADO. @return array{0:array,1:array} */
    public function respuesta(Pedido $pedido): array
    {
        return [[
            'id_pedido' => $pedido->id, 'id_cuenta' => $pedido->id_cuenta, 'monto' => (int) $pedido->monto,
            'estado' => Pedido::PAGADO, 'timestamp' => (int) $pedido->pagado_en, 'firma_t' => $pedido->firma_comprobante,
        ], [
            'id_pedido' => $pedido->id, 'monto' => (int) $pedido->monto, 'ids' => $pedido->idsTickets(),
            'timestamp' => (int) $pedido->recibo_ts, 'firma_b' => $pedido->recibo_firma_b,
        ]];
    }

    /**
     * @param string[]|null $idsEsperados ids que se enviaron (null al conciliar: el recibo del banco manda)
     * @throws BancoNoDisponible si el recibo no es de este pedido, no coincide o no trae la firma del banco
     */
    private function exigirReciboValido(Pedido $pedido, array $recibo, ?array $idsEsperados): void
    {
        $llaves = array_keys($recibo);
        sort($llaves);
        $bien = $llaves === ['firma_b', 'id_pedido', 'ids', 'monto', 'timestamp']
            && is_string($recibo['id_pedido']) && is_int($recibo['monto']) && is_int($recibo['timestamp'])
            && is_string($recibo['firma_b']) && is_array($recibo['ids']) && array_is_list($recibo['ids']) && $recibo['ids'] !== []
            && $recibo['id_pedido'] === $pedido->id && $recibo['monto'] === (int) $pedido->monto;
        if ($bien) {
            foreach ($recibo['ids'] as $id) {
                $bien = $bien && is_string($id);
            }
        }
        if ($bien && $idsEsperados !== null) {
            $bien = $recibo['ids'] === $idsEsperados;
        }
        if (!$bien || !$this->verificador->reciboEsAutentico($recibo)) {
            throw new BancoNoDisponible('El recibo del banco no es válido');
        }
    }

    /** Marca el pedido PAGADO y guarda el comprobante firmado. Si otra petición ganó la carrera, deja lo que ella guardó. */
    private function cobrar(Pedido $pedido, array $recibo, int $ahora): void
    {
        $firma = $this->cripto->firmar(Canonico::comprobante($pedido->id, $pedido->id_cuenta, (int) $pedido->monto, Pedido::PAGADO, $ahora));
        if (!$pedido->marcarPagado($recibo['ids'], $ahora, $firma, $recibo['timestamp'], $recibo['firma_b']) && $pedido->estado !== Pedido::PAGADO) {
            throw new BancoNoDisponible('No se pudo registrar el pago');
        }
    }
}
