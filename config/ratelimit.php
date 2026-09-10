<?php

return [

    'store' => env('RATE_LIMIT_STORE') ?: null,

    'login' => [
        'per_email' => (int) env('RATE_LIMIT_LOGIN_PER_EMAIL', 10),
        'per_ip' => (int) env('RATE_LIMIT_LOGIN_PER_IP', 60),
    ],

    'forgot_password' => [
        'per_email' => (int) env('RATE_LIMIT_FORGOT_PER_EMAIL', 10),
        'per_ip' => (int) env('RATE_LIMIT_FORGOT_PER_IP', 60),
    ],

    'api' => [
        'per_identity' => (int) env('RATE_LIMIT_API_PER_IDENTITY', 300),
        'per_ip' => (int) env('RATE_LIMIT_API_PER_IP', 600),
    ],

    'heavy' => [
        'per_identity' => (int) env('RATE_LIMIT_HEAVY_PER_IDENTITY', 30),
    ],

    'channel_api_per_second' => (int) env('RATE_LIMIT_CHANNEL_API_PER_SECOND', 8),

    'stock_cutover' => [
        'page_per_minute' => (int) env('RATE_LIMIT_STOCK_CUTOVER_PAGE', 30),
        'preview_per_minute' => (int) env('RATE_LIMIT_STOCK_CUTOVER_PREVIEW', 10),
        'status_per_minute' => (int) env('RATE_LIMIT_STOCK_CUTOVER_STATUS', 180),
        'apply_per_minute' => (int) env('RATE_LIMIT_STOCK_CUTOVER_APPLY', 10),
        'report_per_minute' => (int) env('RATE_LIMIT_STOCK_CUTOVER_REPORT', 60),
    ],

];
