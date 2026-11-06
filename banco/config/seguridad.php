<?php

return [
    // Secreto para firmar los tokens JWT de sesión (viene del .env)
    'jwt_secret' => env('JWT_SECRET'),

    // Vida del token en segundos (900 = 15 minutos)
    'jwt_ttl' => (int) env('JWT_TTL', 900),

    // Carpeta con las llaves del servidor (fuera de Git)
    'keys_path' => storage_path('app/keys'),
];
