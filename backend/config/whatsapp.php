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

    // Inbound webhook (POST /api/public/whatsapp/inbound). Requests must carry this secret in the
    // X-Webhook-Secret header (or ?token=). Empty = inbound disabled (the endpoint answers 503).
    // Cloud API: the same value is the webhook verify token; with WHATSAPP_CLOUD_APP_SECRET set, a valid
    // X-Hub-Signature-256 is accepted instead of the header.
    'inbound' => [
        'secret' => env('WHATSAPP_INBOUND_SECRET', ''),
        'cloud_app_secret' => env('WHATSAPP_CLOUD_APP_SECRET', ''),
    ],
];
