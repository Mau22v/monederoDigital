<?php

namespace App\Http\Controllers;

use App\Services\ProcesadorSobre;
use App\Support\Contexto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/cuenta/consulta (contrato, sección 9). */
class CuentaController extends Controller
{
    public function __construct(private readonly ProcesadorSobre $sobre)
    {
    }

    public function consulta(Request $request): JsonResponse
    {
        return $this->sobre->ejecutar($request, '/api/cuenta/consulta', ['jwt' => true, 'nonce' => true], function (Contexto $c): array {
            // Este endpoint no lleva firma del cliente: se guarda el nonce de inmediato.
            $c->registrarNonce($c->cuenta->id);
            $c->detalle = 'Consulta de saldo';

            return [200, ['saldo' => $c->cuenta->saldo]];
        });
    }
}
