<?php

use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\Dev\CriptoPruebaController;
use App\Http\Controllers\PedidoController;
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

// Endpoints de la tienda (contrato, sección 10)
Route::get('/catalogo', [CatalogoController::class, 'index']);
Route::post('/pedidos', [PedidoController::class, 'crear']);
Route::post('/pedidos/{idPedido}/estado', [PedidoController::class, 'estado']);

// Endpoints de prueba de criptografía: SOLO en desarrollo. Quitar antes de la entrega final.
if (app()->environment('local')) {
    Route::post('/dev/eco', [CriptoPruebaController::class, 'eco']);
    Route::get('/dev/firma', [CriptoPruebaController::class, 'firma']);
    Route::post('/dev/verificar', [CriptoPruebaController::class, 'verificar']);
}
