<?php

return [

    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,

            'after_commit' => true,
        ],

        'redis-long' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_LONG_QUEUE', 'downloads'),
            'retry_after' => (int) env('REDIS_LONG_QUEUE_RETRY_AFTER', 1200),
            'block_for' => null,
            'after_commit' => true,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'redis',
                'deferred',
            ],
        ],

    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

    'names' => [
        'orders' => env('QUEUE_NAME_ORDERS', 'orders'),
        'fulfillment' => env('QUEUE_NAME_FULFILLMENT', 'fulfillment'),
        'stock_sync' => env('QUEUE_NAME_STOCK_SYNC', 'stock-sync'),
        'stock_critical' => env('QUEUE_NAME_STOCK_CRITICAL', 'stock-critical'),
        'stock_default' => env('QUEUE_NAME_STOCK_DEFAULT', 'stock-default'),

        'channel_sync' => env('QUEUE_NAME_CHANNEL_SYNC', 'channel-sync'),
        'channel_cancellation' => env('QUEUE_NAME_CHANNEL_CANCELLATION', 'channel-cancellation'),
        'channel_stock' => env('QUEUE_NAME_CHANNEL_STOCK', 'channel-stock'),
        'channel_product' => env('QUEUE_NAME_CHANNEL_PRODUCT', 'channel-product'),
        'channel_finance' => env('QUEUE_NAME_CHANNEL_FINANCE', 'channel-finance'),
        'channel_after_sales' => env('QUEUE_NAME_CHANNEL_AFTER_SALES', 'channel-after-sales'),
        'channel_fulfillment' => env('QUEUE_NAME_CHANNEL_FULFILLMENT', 'channel-fulfillment'),
        'labels' => env('QUEUE_NAME_LABELS', 'labels'),

        'shopee_orders' => env('QUEUE_NAME_SHOPEE_ORDERS', 'shopee-orders'),
        'shopee_tracking' => env('QUEUE_NAME_SHOPEE_TRACKING', 'shopee-tracking'),
        'shopee_catalog' => env('QUEUE_NAME_SHOPEE_CATALOG', 'shopee-catalog'),
        'shopee_aftersales' => env('QUEUE_NAME_SHOPEE_AFTERSALES', 'shopee-aftersales'),
        'shopee_webhooks' => env('QUEUE_NAME_SHOPEE_WEBHOOKS', 'shopee-webhooks'),

        'tiktok_orders' => env('QUEUE_NAME_TIKTOK_ORDERS', 'tiktok-orders'),
        'tiktok_packages' => env('QUEUE_NAME_TIKTOK_PACKAGES', 'tiktok-packages'),
        'tiktok_catalog' => env('QUEUE_NAME_TIKTOK_CATALOG', 'tiktok-catalog'),
        'tiktok_aftersales' => env('QUEUE_NAME_TIKTOK_AFTERSALES', 'tiktok-aftersales'),
        'tiktok_webhooks' => env('QUEUE_NAME_TIKTOK_WEBHOOKS', 'tiktok-webhooks'),

        'lazada_orders' => env('QUEUE_NAME_LAZADA_ORDERS', 'lazada-orders'),
        'lazada_fulfillment' => env('QUEUE_NAME_LAZADA_FULFILLMENT', 'lazada-fulfillment'),
        'lazada_catalog' => env('QUEUE_NAME_LAZADA_CATALOG', 'lazada-catalog'),
        'lazada_aftersales' => env('QUEUE_NAME_LAZADA_AFTERSALES', 'lazada-aftersales'),
        'lazada_webhooks' => env('QUEUE_NAME_LAZADA_WEBHOOKS', 'lazada-webhooks'),

        'webhook_downloads' => env('QUEUE_NAME_WEBHOOK_DOWNLOADS', 'webhook-downloads'),
        'failed_jobs' => env('QUEUE_NAME_FAILED_JOBS', 'failed-jobs'),
        'product' => env('QUEUE_NAME_PRODUCT', 'product'),
        'downloads' => env('QUEUE_NAME_DOWNLOADS', 'downloads'),
        'exports' => env('QUEUE_NAME_EXPORTS', 'exports'),
    ],

    'dedicated_queues' => [
        env('QUEUE_NAME_EXPORTS', 'exports'),
    ],

    'routing' => [

        'channel_product' => [
            'connection' => env('QUEUE_CHANNEL_PRODUCT_CONNECTION', 'redis-long'),
            'queue' => env('QUEUE_NAME_CHANNEL_PRODUCT', 'channel-product'),
        ],

        'channel_after_sales' => [
            'connection' => env('QUEUE_CHANNEL_AFTER_SALES_CONNECTION', 'redis-long'),
            'queue' => env('QUEUE_NAME_CHANNEL_AFTER_SALES', 'channel-after-sales'),
        ],

        'labels' => [
            'connection' => env('QUEUE_LABEL_CONNECTION', 'redis-long'),
            'queue' => env('QUEUE_NAME_LABELS', 'labels'),
            'parallelism' => (int) env('QUEUE_LABEL_PARALLELISM', 2),
            'rate_limit_attempts' => (int) env('QUEUE_LABEL_RATE_LIMIT_ATTEMPTS', 5),
            'rate_limit_decay_seconds' => (int) env('QUEUE_LABEL_RATE_LIMIT_DECAY_SECONDS', 1),
        ],
    ],

];
