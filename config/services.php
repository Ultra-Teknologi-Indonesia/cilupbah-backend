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

        'finance_statement_path' => env('TIKTOK_FINANCE_STATEMENT_PATH', '/finance/202309/orders/{order_id}/statement_transactions'),

        'dump_instant_payload' => env('TIKTOK_DUMP_INSTANT_PAYLOAD', false),

        'webhook_type_overrides' => json_decode((string) env('TIKTOK_WEBHOOK_TYPE_OVERRIDES', '{}'), true) ?: [],
    ],

    'lazada' => [
        'app_key' => env('LAZADA_APP_KEY'),
        'app_secret' => env('LAZADA_APP_SECRET'),
        'redirect_uri' => env('LAZADA_REDIRECT_URI'),

        'auth_url' => env('LAZADA_AUTH_URL', 'https://auth.lazada.com'),

        'base_url' => env('LAZADA_BASE_URL', 'https://api.lazada.co.id/rest'),
    ],

    'woocommerce' => [
        'app_name' => env('WOOCOMMERCE_APP_NAME', 'Cilupbah'),
        'api_version' => env('WOOCOMMERCE_API_VERSION', 'wc/v3'),
        'callback_url' => env('WOOCOMMERCE_CALLBACK_URL'),
        'verify_webhook_signature' => env('WOOCOMMERCE_VERIFY_WEBHOOK_SIGNATURE', true),
    ],

    'shopee' => [
        'partner_id' => env('SHOPEE_PARTNER_ID'),
        'partner_key' => env('SHOPEE_PARTNER_KEY'),
        'push_partner_key' => env('SHOPEE_PUSH_PARTNER_KEY'),
        'verify_push_signature' => env('SHOPEE_VERIFY_PUSH_SIGNATURE', true),
        'redirect_uri' => env('SHOPEE_REDIRECT_URI'),
        'push_url' => env('SHOPEE_PUSH_URL'),
        'host' => env('SHOPEE_HOST', 'https://partner.shopeemobile.com'),
    ],

];
