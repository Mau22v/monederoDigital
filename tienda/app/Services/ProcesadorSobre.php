<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Bitacora;
use App\Models\Nonce;
use App\Support\ApiError;
use App\Support\Contexto;
use App\Support\Validar;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Tubería común de los endpoints con sobre cifrado de la TIENDA. Aplica el orden del contrato (sección 13):
 *
 *   1. descifrar el sobre ............. MENSAJE_CORRUPTO
 *   2. (JWT: no aplica a la tienda; aquí la autenticación es Cert_U + firma_u, en el paso 6)
 *   3. validar formato ................ FORMATO_INVALIDO
 *   4. validar timestamp (±120 s) ..... TIMESTAMP_FUERA_DE_RANGO
 *   5. consultar el nonce ............. NONCE_REPETIDO
 *   6-8. certificado, firma, guardar nonce y reglas de negocio: las hace la lógica de cada endpoint.
 *
 * Opciones: 'nonce' (bool: el mensaje trae nonce y timestamp),
 *           'validar' (Closure que recibe los datos y lanza ApiError si el formato es incorrecto).
 * La lógica recibe un Contexto y devuelve [estado HTTP, cuerpo]; el cuerpo se cifra con la misma K.
 */
class ProcesadorSobre
{
    public const TOLERANCIA_SEG = 120;

    public function __construct(private readonly CryptoService $cripto)
    {
    }

    public function ejecutar(Request $request, string $endpoint, array $opciones, Closure $logica): JsonResponse
    {
        $ahora = now()->timestamp;
        $ip = $request->ip();
        $ctx = null;

        try {
            // 1. Descifrar
            try {
                [$plano, $k] = $this->cripto->abrirSobre($request->json()->all());
            } catch (MensajeCorruptoException) {
                throw new ApiError('MENSAJE_CORRUPTO');
            }

            // 3. Formato
            $datos = json_decode($plano, true, 16);
            if (!is_array($datos) || $datos === [] || array_is_list($datos)) {
                throw new ApiError('FORMATO_INVALIDO');
            }
            if (!empty($opciones['nonce'])) {
                Validar::nonce($datos['nonce'] ?? null);
                Validar::timestamp($datos['timestamp'] ?? null);
            }
            if (isset($opciones['validar'])) {
                ($opciones['validar'])($datos);
            }

            // 4 y 5. Timestamp y nonce
            if (!empty($opciones['nonce'])) {
                if (abs($datos['timestamp'] - $ahora) > self::TOLERANCIA_SEG) {
                    throw new ApiError('TIMESTAMP_FUERA_DE_RANGO');
                }
                if (Nonce::yaUsado($datos['nonce'])) {
                    throw new ApiError('NONCE_REPETIDO');
                }
            }

            // 6-8. Lógica del endpoint
            $ctx = new Contexto($datos, $ahora, $endpoint);
            [$http, $cuerpo] = $logica($ctx);

            $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $respuesta = $this->cripto->cerrarRespuesta($json, $k);
            Bitacora::aceptado($endpoint, $ahora, $ctx->idCuenta, $ctx->detalle, $ip);

            return response()->json($respuesta, $http);
        } catch (ApiError $e) {
            return $this->rechazar($e, $endpoint, $ahora, $ctx?->idCuenta, $ip);
        } catch (Throwable $e) {
            report($e);   // el detalle va al log del servidor (storage/logs), nunca a la respuesta

            return $this->rechazar(new ApiError('ERROR_INTERNO'), $endpoint, $ahora, $ctx?->idCuenta, $ip, class_basename($e));
        }
    }

    /** Respuesta de error EN CLARO (contrato, sección 12) y registro en la bitácora. */
    private function rechazar(ApiError $e, string $endpoint, int $ahora, ?string $idCuenta, ?string $ip, ?string $detalle = null): JsonResponse
    {
        try {
            Bitacora::rechazado($endpoint, $e->codigo, $ahora, $idCuenta, $detalle ?? $e->getMessage(), $ip);
        } catch (Throwable $falla) {
            report($falla);   // un fallo al escribir la bitácora no debe tapar la respuesta de error
        }

        return response()->json(['error' => ['codigo' => $e->codigo, 'mensaje' => $e->getMessage()]], $e->http());
    }
}
