<?php

namespace App\Http\Controllers;

use App\Models\Canje;
use App\Services\CanjeadorTickets;
use App\Services\Canonico;
use App\Services\CryptoService;
use App\Services\FirmaTienda;
use App\Services\ProcesadorSobre;
use App\Support\ApiError;
use App\Support\Contexto;
use App\Support\Validar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/canje y POST /api/canjes/consulta: los invoca la TIENDA (contrato, sección 9). */
class CanjeController extends Controller
{
    public function __construct(
        private readonly ProcesadorSobre $sobre,
        private readonly CryptoService $cripto,
        private readonly FirmaTienda $tienda,
        private readonly CanjeadorTickets $canjeador,
    ) {
    }

    public function canjear(Request $request): JsonResponse
    {
        $opciones = [
            'nonce' => true,
            // Paso 3: formato de todos los campos.
            'validar' => function (array $d): void {
                Validar::uuid($d['id_pedido'] ?? null);
                Validar::monto($d['monto'] ?? null);
                Validar::idsTickets($d['ids'] ?? null);
                Validar::ordenCanje($d['orden'] ?? null);
                Validar::firmaBase64($d['firma_t'] ?? null);
            },
        ];

        return $this->sobre->ejecutar($request, '/api/canje', $opciones, function (Contexto $c): array {
            $d = $c->datos;
            $orden = $d['orden'];
            $ids = $d['ids'];
            sort($ids, SORT_STRING);

            // Paso 6a: el mensaje debe venir firmado por la tienda.
            $this->tienda->verificar(Canonico::canje($d['id_pedido'], $d['monto'], $ids, $d['nonce'], $d['timestamp']), $d['firma_t']);

            // Paso 6b: la orden de pago debe estar firmada por la DUEÑA de los tickets, con su Pub_U registrada.
            $cuenta = $this->canjeador->propietario($ids);
            $c->idCuenta = $cuenta->id;
            if ($cuenta->cert_expiracion <= $c->ahora) {
                throw new ApiError('CERTIFICADO_INVALIDO');
            }
            $cadenaOrden = Canonico::orden($d['id_pedido'], $d['monto'], $ids, $orden['nonce'], $orden['timestamp']);
            if (!$this->cripto->verificar($cadenaOrden, $orden['firma_u'], $this->cripto->pemDesdeBase64($cuenta->pub_u))) {
                throw new ApiError('FIRMA_INVALIDA');
            }

            // Paso 7: solo ahora, con las dos firmas verificadas, se guarda el nonce.
            $c->registrarNonce('tienda');

            // Paso 8: reglas de negocio y canje atómico.
            $recibo = $this->canjeador->canjear($d['id_pedido'], $d['monto'], $ids, $cuenta, $c->ahora);
            $c->detalle = 'Canje ' . substr($d['id_pedido'], 0, 8) . ' por $' . $recibo['monto'];

            return [200, ['recibo' => $recibo]];
        });
    }

    public function consultar(Request $request): JsonResponse
    {
        $opciones = [
            'nonce' => true,
            'validar' => function (array $d): void {
                Validar::uuid($d['id_pedido'] ?? null);
                Validar::firmaBase64($d['firma_t'] ?? null);
            },
        ];

        return $this->sobre->ejecutar($request, '/api/canjes/consulta', $opciones, function (Contexto $c): array {
            $d = $c->datos;
            $this->tienda->verificar(Canonico::consultaCanje($d['id_pedido'], $d['nonce'], $d['timestamp']), $d['firma_t']);
            $c->registrarNonce('tienda');

            $canje = Canje::find($d['id_pedido']);
            if ($canje === null) {
                throw new ApiError('CANJE_NO_ENCONTRADO');
            }
            $c->idCuenta = $canje->id_cuenta;
            $c->detalle = 'Consulta de canje ' . substr($canje->id_pedido, 0, 8);

            return [200, ['recibo' => $this->canjeador->recibo($canje)]];
        });
    }
}
