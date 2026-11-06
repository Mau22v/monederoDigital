<?php

use Illuminate\Support\Facades\Route;

// Ruta de verificación: confirma que la API responde y que el hash es Argon2id
Route::get('/ping', function () {
    return response()->json([
        'servidor' => 'banco',
        'estado'   => 'ok',
        'hash'     => config('hashing.driver'),
        'hora'     => now()->toIso8601String(),
    ]);
});
