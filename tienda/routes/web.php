<?php

use App\Http\Controllers\PanelController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Panel web de SOLO LECTURA (Fase 9). Solo rutas GET: cualquier otro método responde 405.
Route::prefix('panel')->name('panel.')->controller(PanelController::class)->group(function () {
    Route::get('/', 'inicio')->name('inicio');
    Route::get('/bitacora', 'bitacora')->name('bitacora');
    Route::get('/productos', 'productos')->name('productos');
    Route::get('/pedidos', 'pedidos')->name('pedidos');
    // El pedido se identifica por los 8 primeros caracteres de su id (lo mismo que muestra el panel).
    Route::get('/pedidos/{prefijo}', 'pedido')->where('prefijo', '[0-9a-f]{8}')->name('pedido');
});
