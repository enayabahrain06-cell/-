<?php

return [

    /*
    | The frontend talks only to /api. Allowed origins come from .env:
    |   FRONTEND_URL=http://localhost:5173
    |   CORS_ALLOWED_ORIGINS=https://app.example.bh,https://staging.example.bh   (optional, comma-separated)
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'docs', 'docs/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173')))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => true,

];
