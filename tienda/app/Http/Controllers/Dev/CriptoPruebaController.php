<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Services\Canonico;
use App\Services\CryptoService;
use App\Services\MensajeCorruptoException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SOLO DESARROLLO (rutas registradas únicamente en entorno "local").
 * Sirve para que la app Android compruebe que su criptografía coincide con la del servidor.
 * Se elimina o se desactiva antes de la entrega final.
 */
class CriptoPruebaController extends Controller
{
    public function __construct(private readonly CryptoService $cripto)
    {
    }

    /** POST /api/dev/eco: abre el sobre, y devuelve el texto recibido cifrado con la misma K. */
    public function eco(Request $request): JsonResponse
    {
        try {
            [$plano, $k] = $this->cripto->abrirSobre($request->json()->all());
        } catch (MensajeCorruptoException) {
            return response()->json(['error' => 'MENSAJE_CORRUPTO'], 400);
        }

        return response()->json($this->cripto->cerrarRespuesta('eco:' . $plano, $k));
    }

    /** GET /api/dev/firma: firma un ticket de ejemplo para que la app verifique con la llave pública pineada. */
    public function firma(): JsonResponse
    {
        $mensaje = Canonico::ticket(
            '3f2b8c1e-5d4a-4e0b-9c7a-1b2c3d4e5f60', 500,
            '7a1d9e22-0c3b-4f8a-8b11-aa22bb33cc44', 1762500000, 1765092000
        );

        return response()->json(['mensaje' => $mensaje, 'firma' => $this->cripto->firmar($mensaje)]);
    }

    /** POST /api/dev/verificar {mensaje, firma, pub_u}: verifica una firma hecha por la app (pub_u en Base64 X.509). */
    public function verificar(Request $request): JsonResponse
    {
        $d = $request->json()->all();
        foreach (['mensaje', 'firma', 'pub_u'] as $campo) {
            if (!isset($d[$campo]) || !is_string($d[$campo])) {
                return response()->json(['valida' => false], 422);
            }
        }
        try {
            $pem = $this->cripto->pemDesdeBase64($d['pub_u']);
        } catch (\InvalidArgumentException) {
            return response()->json(['valida' => false], 422);
        }

        return response()->json(['valida' => $this->cripto->verificar($d['mensaje'], $d['firma'], $pem)]);
    }
}
