<?php

return [

    // log | openwa | cloud
    'provider' => env('WHATSAPP_PROVIDER', 'log'),

    'openwa' => [
        'base_url' => rtrim(env('OPENWA_BASE_URL', 'http://localhost:8085'), '/'),
        'api_key' => env('OPENWA_API_KEY', ''),
        'timeout' => 20,
    ],

    'cloud' => [
        'token' => env('WHATSAPP_CLOUD_TOKEN', ''),
        'phone_number_id' => env('WHATSAPP_CLOUD_PHONE_NUMBER_ID', ''),
        'api_version' => env('WHATSAPP_CLOUD_API_VERSION', 'v20.0'),
        'timeout' => 20,
    ],

    'queue' => env('WHATSAPP_QUEUE', 'whatsapp'),
    'delay_min' => (int) env('WHATSAPP_DELAY_MIN', 3),
    'delay_max' => (int) env('WHATSAPP_DELAY_MAX', 5),
    'max_tries' => (int) env('WHATSAPP_MAX_TRIES', 3),
    'backoff_seconds' => [30, 120, 300],
];
