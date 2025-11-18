<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        // Angular local
        'http://localhost:4200',
        'http://172.31.208.1:4200',

        // Lo que te está saliendo en el error del navegador
        'https://localhost',

        // Cuando la app corre dentro de Capacitor (Android/iOS)
        'capacitor://localhost',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Como usas token en localStorage y no cookies, esto en false está bien
    'supports_credentials' => false,
];
