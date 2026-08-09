<?php

$origins = array_values(array_filter(array_map('trim', explode(',', (string) env(
    'CORS_ALLOWED_ORIGINS',
    (string) env('FRONTEND_URL', '')
)))));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,

];
