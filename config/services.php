<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials' => env('FCM_CREDENTIALS', storage_path('app/firebase-credentials.json')),
    ],

    'tiktok' => [
        'app_key' => env('TIKTOK_APP_KEY'),
        'app_secret' => env('TIKTOK_APP_SECRET'),
        'redirect_uri' => env('TIKTOK_REDIRECT_URI'),
        'base_url' => env('TIKTOK_BASE_URL', 'https://open-api.tiktokglobalshop.com'),
    ],

    'lazada' => [
        'app_key' => env('LAZADA_APP_KEY'),
        'app_secret' => env('LAZADA_APP_SECRET'),
        'redirect_uri' => env('LAZADA_REDIRECT_URI'),

        'auth_url' => env('LAZADA_AUTH_URL', 'https://auth.lazada.com'),

        'base_url' => env('LAZADA_BASE_URL', 'https://api.lazada.co.id/rest'),
    ],

    'shopee' => [
        'partner_id' => env('SHOPEE_PARTNER_ID'),
        'partner_key' => env('SHOPEE_PARTNER_KEY'),
        'push_partner_key' => env('SHOPEE_PUSH_PARTNER_KEY'),
        'redirect_uri' => env('SHOPEE_REDIRECT_URI'),
        'push_url' => env('SHOPEE_PUSH_URL'),
        'host' => env('SHOPEE_HOST', 'https://partner.shopeemobile.com'),
    ],

];
