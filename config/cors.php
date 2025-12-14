<?php

return [
    'paths' => [
        'api/*',
        'login',
        'login/*',
        'logout',
        'register',
        'specialties',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'https://localhost:5173',
        'https://127.0.0.1:5173',
    ],

    'allowed_origins_patterns' => [
        '#^https?://localhost(:5173)?$#',
        '#^https?://127\.0\.0\.1(:5173)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];

