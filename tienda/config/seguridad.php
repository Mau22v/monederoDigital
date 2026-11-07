<?php

return [
    // Dirección del servidor Banco (viene del .env)
    'banco_url' => env('BANCO_URL'),

    // Carpeta con las llaves del servidor (fuera de Git)
    'keys_path' => storage_path('app/keys'),
];
