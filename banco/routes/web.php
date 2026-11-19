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
    Route::get('/cuentas', 'cuentas')->name('cuentas');
    Route::get('/tickets', 'tickets')->name('tickets');
    Route::get('/canjes', 'canjes')->name('canjes');
});
