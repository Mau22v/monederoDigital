<?php

namespace App\Http\Controllers;

use App\Services\Canonico;
use App\Services\CryptoService;
use App\Services\EmisorTickets;
use App\Services\ProcesadorSobre;
use App\Support\ApiError;
use App\Support\Contexto;
use App\Support\Validar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/tickets/solicitar (contrato, sección 9). */
class TicketController extends Controller
{
    public function __construct(
        private readonly ProcesadorSobre $sobre,
        private readonly CryptoService $cripto,
        private readonly EmisorTickets $emisor,
    ) {
    }

    public function solicitar(Request $request): JsonResponse
    {
        $opciones = [
            'jwt' => true,
            'nonce' => true,
            // Paso 3 del orden de verificación: formato de todos los campos.
            'validar' => function (array $d): void {
                Validar::uuid($d['id_cuenta'] ?? null);
                Validar::denominaciones($d['denominaciones'] ?? null);
                Validar::firmaBase64($d['firma_u'] ?? null);
            },
        ];

        return $this->sobre->ejecutar($request, '/api/tickets/solicitar', $opciones, function (Contexto $c): array {
            $cuenta = $c->cuenta;

            // La sesión (JWT) y la cuenta de la solicitud deben ser la misma.
            if ($c->datos['id_cuenta'] !== $cuenta->id) {
                throw new ApiError('TOKEN_INVALIDO', 'La sesión no corresponde a esa cuenta');
            }
            // Paso 6: certificado vigente y firma del cliente sobre la cadena canónica.
            if ($cuenta->cert_expiracion <= $c->ahora) {
                throw new ApiError('CERTIFICADO_INVALIDO');
            }
            $denominaciones = $c->datos['denominaciones'];
            $cadena = Canonico::solicitud($cuenta->id, $denominaciones, $c->datos['nonce'], $c->datos['timestamp']);
            $pubU = $this->cripto->pemDesdeBase64($cuenta->pub_u);
            if (!$this->cripto->verificar($cadena, $c->datos['firma_u'], $pubU)) {
                throw new ApiError('FIRMA_INVALIDA');
            }
            // Paso 7: solo ahora, con la firma verificada, se guarda el nonce.
            $c->registrarNonce($cuenta->id);

            // Paso 8: regla de negocio (saldo) y emisión.
            $resultado = $this->emisor->emitir($cuenta, $denominaciones, $c->ahora);
            $c->detalle = 'Emitidos ' . count($resultado['tickets']) . ' tickets por $' . $resultado['recibo']['total'];

            return [201, $resultado];
        });
    }
}
