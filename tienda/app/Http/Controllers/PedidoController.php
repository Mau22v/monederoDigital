<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Services\Canonico;
use App\Services\CertificadoUsuario;
use App\Services\CreadorPedidos;
use App\Services\CryptoService;
use App\Services\ProcesadorSobre;
use App\Support\ApiError;
use App\Support\Contexto;
use App\Support\Validar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/pedidos y POST /api/pedidos/{idPedido}/estado (contrato, sección 10). */
class PedidoController extends Controller
{
    public function __construct(
        private readonly ProcesadorSobre $sobre,
        private readonly CryptoService $cripto,
        private readonly CertificadoUsuario $usuarios,
        private readonly CreadorPedidos $creador,
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

            // El comprobante firmado se agrega en la Fase 8, cuando existan los pagos (hoy ningún pedido está PAGADO).
            return [200, ['id_pedido' => $pedido->id, 'estado' => $pedido->estadoEfectivo($c->ahora), 'comprobante' => null]];
        });
    }
}
