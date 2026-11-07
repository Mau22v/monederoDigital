<?php

use Illuminate\Support\Facades\Route;

//ruta de verificacion: confirma que la API responde y que el hash es Argon2id
Route::get('/ping', function () {
    return response()->json([
        'servidor' => 'tienda',
        'estado'   => 'ok',
        'hash'     => config('hashing.driver'),
        'hora'     => now()->toIso8601String(),
    ]);
});