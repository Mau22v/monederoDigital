<?php

return [
    'nombre' => 'tienda',

    'banco_url' => env('BANCO_URL', 'http://localhost:8001'),

    // Llaves propias (privadas: storage/app/keys, fuera de Git)
    'keys_path' => storage_path('app/keys'),
    'rsa_priv'  => storage_path('app/keys/rsa_priv.pem'),
    'ec_priv'   => storage_path('app/keys/ec_priv.pem'),

    // Llaves públicas del banco (en el repositorio)
    'banco_rsa_pub' => base_path('../claves-publicas/banco_rsa_pub.pem'),
    'banco_ec_pub'  => base_path('../claves-publicas/banco_ec_pub.pem'),
];
