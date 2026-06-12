<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

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
        // Endpoint OAuth (token create/refresh) Lazada.
        'auth_url' => env('LAZADA_AUTH_URL', 'https://auth.lazada.com'),
        // Base API region (mis. Indonesia). Token tetap lewat auth_url di atas.
        'base_url' => env('LAZADA_BASE_URL', 'https://api.lazada.co.id/rest'),
    ],

];
