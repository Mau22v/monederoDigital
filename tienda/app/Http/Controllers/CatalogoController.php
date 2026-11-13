<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Producto;
use App\Services\Canonico;
use App\Services\CryptoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** GET /api/catalogo (contrato, sección 10). Público, sin sobre, pero FIRMADO por la tienda. */
class CatalogoController extends Controller
{
    public function __construct(private readonly CryptoService $cripto)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $ahora = now()->timestamp;
        try {
            $productos = Producto::catalogo();
            $firma = $this->cripto->firmar(Canonico::catalogo($ahora, $productos));
            Bitacora::aceptado('/api/catalogo', $ahora, null, count($productos) . ' productos', $request->ip());

            return response()->json(['timestamp' => $ahora, 'productos' => $productos, 'firma_t' => $firma]);
        } catch (Throwable $e) {
            report($e);
            try {
                Bitacora::rechazado('/api/catalogo', 'ERROR_INTERNO', $ahora, null, class_basename($e), $request->ip());
            } catch (Throwable $falla) {
                report($falla);
            }

            return response()->json(['error' => ['codigo' => 'ERROR_INTERNO', 'mensaje' => 'Error interno del servidor']], 500);
        }
    }
}
