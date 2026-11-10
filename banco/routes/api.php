<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CuentaController;
use App\Http\Controllers\Dev\CriptoPruebaController;
use App\Services\CryptoService;
use Illuminate\Support\Facades\Route;

Route::get('/ping', function (CryptoService $cripto) {
    return response()->json([
        'servidor' => config('seguridad.nombre'),
        'estado'   => 'ok',
        'hash'     => $cripto->sha256Hex('ping'),
        'hora'     => time(),
    ]);
});

// Endpoints del banco (contrato, sección 9)
Route::post('/registro', [AuthController::class, 'registro']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/cuenta/consulta', [CuentaController::class, 'consulta']);

// Endpoints de prueba de criptografía: SOLO en desarrollo. Quitar antes de la entrega final.
if (app()->environment('local')) {
    Route::post('/dev/eco', [CriptoPruebaController::class, 'eco']);
    Route::get('/dev/firma', [CriptoPruebaController::class, 'firma']);
    Route::post('/dev/verificar', [CriptoPruebaController::class, 'verificar']);
}
