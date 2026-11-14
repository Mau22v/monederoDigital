<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Services\Canonico;
use App\Services\CertificadoUsuario;
use App\Services\CreadorPedidos;
use App\Services\ProcesadorPagos;
use App\Services\CryptoService;
use App\Services\ProcesadorSobre;
use App\Support\ApiError;
use App\Support\Contexto;
use App\Support\Validar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/pedidos, POST /api/pedidos/{idPedido}/pago y POST /api/pedidos/{idPedido}/estado (contrato, sección 10). */
class PedidoController extends Controller
{
    public function __construct(
        private readonly ProcesadorSobre $sobre,
        private readonly CryptoService $cripto,
        private readonly CertificadoUsuario $usuarios,
        private readonly CreadorPedidos $creador,
        private readonly ProcesadorPagos $pagos,
    ) {
    }

    public function crear(Request $request): JsonResponse
    {
        $opciones = [
            'nonce' => true,
            // Paso 3 del orden de verificación: formato de todos los campos.
            'validar' => function (array $d): void {
                Validar::certU($d['cert_u'] ?? null, $this->cripto);
                Validar::items($d['items'] ?? null);
                Validar::firmaBase64($d['firma_u'] ?? null);
            },
        ];

        return $this->sobre->ejecutar($request, '/api/pedidos', $opciones, function (Contexto $c): array {
            $cert = $c->datos['cert_u'];
            $items = $c->datos['items'];

            // Paso 6: Cert_U firmado por el banco y vigente; después, firma del cliente con la Pub_U de ESE certificado.
            $this->usuarios->verificarCert($cert, $c->ahora);
            $c->idCuenta = $cert['id_cuenta'];
            $cadena = Canonico::crearPedido($cert['id_cuenta'], $items, $c->datos['nonce'], $c->datos['timestamp']);
            $this->usuarios->verificarFirmaCliente($cert, $cadena, $c->datos['firma_u']);
            // Paso 7: solo ahora, con las firmas verificadas, se guarda el nonce.
            $c->registrarNonce($cert['id_cuenta']);

            // Paso 8: reglas de negocio (productos, monto) y creación del pedido.
            $pedido = $this->creador->crear($cert['id_cuenta'], $items, $c->ahora);
            $c->detalle = 'Pedido ' . substr($pedido->id, 0, 8) . ' por $' . $pedido->monto;

            return [201, ['pedido' => [
                'id_pedido' => $pedido->id, 'id_cuenta' => $pedido->id_cuenta, 'monto' => $pedido->monto,
                'nonce_t' => $pedido->nonce_t, 'expiracion' => $pedido->expiracion, 'firma_t' => $pedido->firma_t,
            ]]];
        });
    }

    /**
     * Pago con tickets. El nonce y el timestamp son los de la orden que firmó el cliente (por eso 'anidado' => 'orden').
     * Orden de verificación: sobre -> formato -> timestamp -> nonce -> Cert_U -> firma_u -> tickets -> guardar nonce -> negocio y canje.
     */
    public function pagar(Request $request, string $idPedido): JsonResponse
    {
        $opciones = [
            'nonce' => true,
            'anidado' => 'orden',
            'validar' => function (array $d) use ($idPedido): void {
                Validar::uuid($idPedido);
                Validar::certU($d['cert_u'] ?? null, $this->cripto);
                Validar::ordenPago($d['orden'] ?? null);
                if ($d['orden']['id_pedido'] !== $idPedido) {
                    throw new ApiError('FORMATO_INVALIDO');   // la orden firmada debe ser de ESTE pedido
                }
                Validar::firmaBase64($d['firma_u'] ?? null);
            },
        ];

        return $this->sobre->ejecutar($request, '/api/pedidos/{id}/pago', $opciones, function (Contexto $c) use ($idPedido): array {
            $cert = $c->datos['cert_u'];
            $orden = $c->datos['orden'];
            $tickets = $orden['tickets'];
            $ids = array_column($tickets, 'id');

            // Paso 6: Cert_U, firma del cliente sobre la orden y firma del banco de CADA ticket (E1).
            $this->usuarios->verificarCert($cert, $c->ahora);
            $c->idCuenta = $cert['id_cuenta'];
            $this->usuarios->verificarFirmaCliente($cert, Canonico::orden($idPedido, $orden['monto'], $ids, $orden['nonce'], $orden['timestamp']), $c->datos['firma_u']);
            foreach ($tickets as $ticket) {
                $this->usuarios->verificarTicket($ticket, $cert['id_cuenta']);
            }
            // Paso 7: solo ahora, con todo verificado, se guarda el nonce.
            $c->registrarNonce($cert['id_cuenta']);

            // Paso 8: el pedido debe ser de esta cuenta; reglas de negocio y canje en el banco.
            $pedido = Pedido::delCliente($idPedido, $cert['id_cuenta']);
            if ($pedido === null) {
                throw new ApiError('PEDIDO_NO_ENCONTRADO');
            }
            [$comprobante, $recibo] = $this->pagos->pagar($pedido, $orden, $c->datos['firma_u'], $tickets, $c->ahora);
            $c->detalle = 'Pedido ' . substr($pedido->id, 0, 8) . ' pagado por $' . $comprobante['monto'];

            return [200, ['comprobante' => $comprobante, 'recibo_canje' => $recibo]];
        });
    }

    public function estado(Request $request, string $idPedido): JsonResponse
    {
        $opciones = [
            'nonce' => true,
            'validar' => function (array $d) use ($idPedido): void {
                Validar::uuid($idPedido);
                Validar::certU($d['cert_u'] ?? null, $this->cripto);
                Validar::firmaBase64($d['firma_u'] ?? null);
            },
        ];

        return $this->sobre->ejecutar($request, '/api/pedidos/{id}/estado', $opciones, function (Contexto $c) use ($idPedido): array {
            $cert = $c->datos['cert_u'];

            $this->usuarios->verificarCert($cert, $c->ahora);
            $c->idCuenta = $cert['id_cuenta'];
            $cadena = Canonico::consultaPedido($idPedido, $cert['id_cuenta'], $c->datos['nonce'], $c->datos['timestamp']);
            $this->usuarios->verificarFirmaCliente($cert, $cadena, $c->datos['firma_u']);
            $c->registrarNonce($cert['id_cuenta']);

            // Un pedido ajeno responde igual que uno inexistente: no se revela qué pedidos existen.
            $pedido = Pedido::delCliente($idPedido, $cert['id_cuenta']);
            if ($pedido === null) {
                throw new ApiError('PEDIDO_NO_ENCONTRADO');
            }
            $c->detalle = 'Estado del pedido ' . substr($pedido->id, 0, 8);

            // Si un pago quedó a medias (se cortó la red tras llamar al banco), aquí se concilia con el banco.
            $this->pagos->conciliar($pedido, $c->ahora);
            $comprobante = $pedido->estado === Pedido::PAGADO ? $this->pagos->respuesta($pedido)[0] : null;

            return [200, ['id_pedido' => $pedido->id, 'estado' => $pedido->estadoEfectivo($c->ahora), 'comprobante' => $comprobante]];
        });
    }
}
