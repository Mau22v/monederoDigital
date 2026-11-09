<?php

return [
    'nombre' => 'banco',

    // Sesión (JWT HS256, solo en el banco)
    'jwt_secret' => env('JWT_SECRET'),
    'jwt_ttl'    => (int) env('JWT_TTL', 900),

    // Llaves propias (privadas: storage/app/keys, fuera de Git)
    'keys_path' => storage_path('app/keys'),
    'rsa_priv'  => storage_path('app/keys/rsa_priv.pem'),
    'ec_priv'   => storage_path('app/keys/ec_priv.pem'),

    // Llaves públicas de otros (en el repositorio)
    'tienda_ec_pub' => base_path('../claves-publicas/tienda_ec_pub.pem'),
];
