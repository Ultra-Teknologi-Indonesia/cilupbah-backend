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

            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 660),
            'block_for' => null,

            'after_commit' => true,
        ],

        'redis-channel-sync' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('QUEUE_NAME_CHANNEL_SYNC', 'channel-sync'),
            'retry_after' => (int) env('REDIS_CHANNEL_SYNC_RETRY_AFTER', 360),
            'block_for' => null,
            'after_commit' => true,
        ],

        'redis-long' => [
            'driver' => 'redis',
            'connection' => env('REDIS_LONG_CONNECTION', 'long'),
            'queue' => env('REDIS_LONG_QUEUE', 'downloads'),

            'retry_after' => (int) env('REDIS_LONG_QUEUE_RETRY_AFTER', 2160),
            'block_for' => null,
            'after_commit' => true,
        ],

        'redis-finance' => [
            'driver' => 'redis',
            'connection' => env('REDIS_FINANCE_CONNECTION', 'finance'),
            'queue' => env('REDIS_FINANCE_QUEUE', 'channel-finance'),

            'retry_after' => (int) env('REDIS_FINANCE_QUEUE_RETRY_AFTER', 360),
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
        'warehouse_safety' => env('QUEUE_NAME_WAREHOUSE_SAFETY', 'warehouse-safety'),
        'tracking' => env('QUEUE_NAME_TRACKING', 'tracking'),
        'imports' => env('QUEUE_NAME_IMPORTS', 'imports'),
        'sales' => env('QUEUE_NAME_SALES', 'orders'),

        'channel_sync' => env('QUEUE_NAME_CHANNEL_SYNC', 'channel-sync'),
        'channel_order_recovery' => env('QUEUE_NAME_CHANNEL_ORDER_RECOVERY', 'channel-order-recovery'),
        'channel_cancellation' => env('QUEUE_NAME_CHANNEL_CANCELLATION', 'channel-cancellation'),
        'channel_stock' => env('QUEUE_NAME_CHANNEL_STOCK', 'channel-stock'),
        'channel_stock_critical' => env('QUEUE_NAME_CHANNEL_STOCK_CRITICAL', 'channel-stock-critical'),
        'channel_stock_normal' => env('QUEUE_NAME_CHANNEL_STOCK_NORMAL', 'channel-stock-normal'),
        'channel_product' => env('QUEUE_NAME_CHANNEL_PRODUCT', 'channel-product'),
        'channel_finance' => env('QUEUE_NAME_CHANNEL_FINANCE', 'channel-finance'),
        'channel_after_sales' => env('QUEUE_NAME_CHANNEL_AFTER_SALES', 'channel-after-sales'),
        'channel_fulfillment' => env('QUEUE_NAME_CHANNEL_FULFILLMENT', 'channel-fulfillment'),
        'channel_order_refresh' => env('QUEUE_NAME_CHANNEL_ORDER_REFRESH', 'channel-order-refresh'),
        'shopee_cancellation' => env('QUEUE_NAME_SHOPEE_CANCELLATION', 'shopee-cancellation'),
        'tiktok_cancellation' => env('QUEUE_NAME_TIKTOK_CANCELLATION', 'tiktok-cancellation'),
        'lazada_cancellation' => env('QUEUE_NAME_LAZADA_CANCELLATION', 'lazada-cancellation'),
        'shopee_fulfillment' => env('QUEUE_NAME_SHOPEE_FULFILLMENT', 'shopee-fulfillment'),
        'tiktok_fulfillment' => env('QUEUE_NAME_TIKTOK_FULFILLMENT', 'tiktok-fulfillment'),
        'lazada_fulfillment' => env('QUEUE_NAME_LAZADA_FULFILLMENT', 'lazada-fulfillment'),
        'labels' => env('QUEUE_NAME_LABELS', 'labels'),
        'label_prefetch' => env('QUEUE_NAME_LABEL_PREFETCH', 'label-prefetch'),
        'label_awb_request_shopee' => env('QUEUE_NAME_LABEL_AWB_REQUEST_SHOPEE', 'label-awb-request-shopee'),
        'label_awb_request_tiktok' => env('QUEUE_NAME_LABEL_AWB_REQUEST_TIKTOK', 'label-awb-request-tiktok'),
        'label_awb_request_lazada' => env('QUEUE_NAME_LABEL_AWB_REQUEST_LAZADA', 'label-awb-request-lazada'),
        'label_awb_poll_shopee' => env('QUEUE_NAME_LABEL_AWB_POLL_SHOPEE', 'label-awb-poll-shopee'),
        'label_awb_poll_tiktok' => env('QUEUE_NAME_LABEL_AWB_POLL_TIKTOK', 'label-awb-poll-tiktok'),
        'label_awb_poll_lazada' => env('QUEUE_NAME_LABEL_AWB_POLL_LAZADA', 'label-awb-poll-lazada'),
        'label_download_shopee' => env('QUEUE_NAME_LABEL_DOWNLOAD_SHOPEE', 'label-download-shopee'),
        'label_download_tiktok' => env('QUEUE_NAME_LABEL_DOWNLOAD_TIKTOK', 'label-download-tiktok'),
        'label_download_lazada' => env('QUEUE_NAME_LABEL_DOWNLOAD_LAZADA', 'label-download-lazada'),
        'label_merge' => env('QUEUE_NAME_LABEL_MERGE', 'label-merge'),
        'label_archive' => env('QUEUE_NAME_LABEL_ARCHIVE', 'label-archive'),
        'qr_labels' => env('QUEUE_NAME_QR_LABELS', 'qr-labels'),

        'shopee_orders' => env('QUEUE_NAME_SHOPEE_ORDERS', 'shopee-orders'),

        'shopee_tracking_events' => env('QUEUE_NAME_SHOPEE_TRACKING_EVENTS', 'shopee-tracking-events'),
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
        'lazada_catalog' => env('QUEUE_NAME_LAZADA_CATALOG', 'lazada-catalog'),
        'lazada_aftersales' => env('QUEUE_NAME_LAZADA_AFTERSALES', 'lazada-aftersales'),
        'lazada_webhooks' => env('QUEUE_NAME_LAZADA_WEBHOOKS', 'lazada-webhooks'),

        'webhook_downloads' => env('QUEUE_NAME_WEBHOOK_DOWNLOADS', 'webhook-downloads'),
        'failed_jobs' => env('QUEUE_NAME_FAILED_JOBS', 'failed-jobs'),
        'product' => env('QUEUE_NAME_PRODUCT', 'product'),
        'downloads' => env('QUEUE_NAME_DOWNLOADS', 'downloads'),
        'exports' => env('QUEUE_NAME_EXPORTS', 'exports-sheet'),
        'exports_pdf' => env('QUEUE_NAME_EXPORTS_PDF', 'exports-pdf'),
        'exports_sheet' => env('QUEUE_NAME_EXPORTS_SHEET', 'exports-sheet'),
        'catalog_exports' => env('QUEUE_NAME_CATALOG_EXPORTS', 'catalog-exports'),
    ],

    'channel_order_intake' => [

        'cutoff_at' => env('CHANNEL_ORDER_INTAKE_CUTOFF_AT', '2026-09-16T16:00:00+07:00'),
    ],

    'webhook_retry_window_hours' => (int) env('WEBHOOK_RETRY_WINDOW_HOURS', 24),

    'health' => [
        'queue_ready_warning' => max(1, (int) env('QUEUE_HEALTH_READY_WARNING', 500)),
        'queue_ready_critical' => max(1, (int) env('QUEUE_HEALTH_READY_CRITICAL', 2000)),
        'queue_delayed_warning' => max(1, (int) env('QUEUE_HEALTH_DELAYED_WARNING', 500)),
        'queue_reserved_warning' => max(1, (int) env('QUEUE_HEALTH_RESERVED_WARNING', 100)),
        'queue_oldest_warning_seconds' => max(60, (int) env('QUEUE_HEALTH_OLDEST_WARNING_SECONDS', 300)),
        'queue_oldest_critical_seconds' => max(120, (int) env('QUEUE_HEALTH_OLDEST_CRITICAL_SECONDS', 900)),
        'stale_webhook_warning' => max(1, (int) env('QUEUE_HEALTH_STALE_WEBHOOK_WARNING', 100)),
        'failed_jobs_window_minutes' => max(1, (int) env('QUEUE_HEALTH_FAILED_JOBS_WINDOW_MINUTES', 15)),
        'failed_jobs_warning' => max(1, (int) env('QUEUE_HEALTH_FAILED_JOBS_WARNING', 10)),
        'failed_jobs_critical' => max(1, (int) env('QUEUE_HEALTH_FAILED_JOBS_CRITICAL', 50)),
    ],

    'backpressure' => [
        'enabled' => filter_var(env('QUEUE_BACKPRESSURE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'channel_sync_max_depth' => max(1, min(500, (int) env('CHANNEL_SYNC_MAX_QUEUE_DEPTH', 24))),
        'channel_sync_max_memory_ratio' => max(0.50, min(0.90, (float) env('CHANNEL_SYNC_MAX_REDIS_MEMORY_RATIO', 0.70))),

        'webhook_ingress_max_depth' => max(1, min(10000, (int) env('WEBHOOK_INGRESS_MAX_QUEUE_DEPTH', 1000))),
        'webhook_ingress_max_memory_ratio' => max(0.50, min(0.90, (float) env('WEBHOOK_INGRESS_MAX_REDIS_MEMORY_RATIO', 0.70))),
        'webhook_replay_max_depth' => max(1, min(5000, (int) env('WEBHOOK_REPLAY_MAX_QUEUE_DEPTH', 500))),
    ],

    'dedicated_queues' => [
        env('QUEUE_NAME_EXPORTS_PDF', 'exports-pdf'),
        env('QUEUE_NAME_EXPORTS_SHEET', 'exports-sheet'),
        env('QUEUE_NAME_CATALOG_EXPORTS', 'catalog-exports'),
        env('QUEUE_NAME_IMPORTS', 'imports'),
    ],

    'routing' => [

        'stock_critical' => [
            'connection' => env('QUEUE_STOCK_CRITICAL_CONNECTION', 'redis'),
            'queue' => env('QUEUE_NAME_STOCK_CRITICAL', 'stock-critical'),
        ],

        'stock_default' => [
            'connection' => env('QUEUE_STOCK_DEFAULT_CONNECTION', 'redis'),
            'queue' => env('QUEUE_NAME_STOCK_DEFAULT', 'stock-default'),
        ],

        'channel_stock' => [
            'connection' => env('QUEUE_CHANNEL_STOCK_CONNECTION', 'redis'),
            'queue' => env('QUEUE_NAME_CHANNEL_STOCK', 'channel-stock'),
        ],

        'channel_stock_critical' => [
            'connection' => env('QUEUE_CHANNEL_STOCK_CRITICAL_CONNECTION', 'redis'),
            'queue' => env('QUEUE_NAME_CHANNEL_STOCK_CRITICAL', 'channel-stock-critical'),
        ],

        'channel_stock_normal' => [
            'connection' => env('QUEUE_CHANNEL_STOCK_NORMAL_CONNECTION', 'redis'),
            'queue' => env('QUEUE_NAME_CHANNEL_STOCK_NORMAL', 'channel-stock-normal'),
        ],

        'channel_stock_outbox' => [
            'connection' => env('QUEUE_CHANNEL_STOCK_OUTBOX_CONNECTION', 'redis'),
            'queue' => env('QUEUE_NAME_CHANNEL_STOCK_OUTBOX', 'channel-stock-outbox'),
        ],

        'warehouse_safety' => [
            'connection' => env('QUEUE_WAREHOUSE_SAFETY_CONNECTION', 'redis'),
            'queue' => env('QUEUE_NAME_WAREHOUSE_SAFETY', 'warehouse-safety'),
        ],

        'channel_product' => [
            'connection' => env('QUEUE_CHANNEL_PRODUCT_CONNECTION', 'redis-long'),
            'queue' => env('QUEUE_NAME_CHANNEL_PRODUCT', 'channel-product'),
        ],

        'channel_after_sales' => [
            'connection' => env('QUEUE_CHANNEL_AFTER_SALES_CONNECTION', 'redis-long'),
            'queue' => env('QUEUE_NAME_CHANNEL_AFTER_SALES', 'channel-after-sales'),
        ],

        'channel_finance' => [
            'connection' => env('QUEUE_CHANNEL_FINANCE_CONNECTION', 'redis-finance'),
            'queue' => env('QUEUE_NAME_CHANNEL_FINANCE', 'channel-finance'),
        ],

        'channel_sync' => [
            'connection' => env('QUEUE_CHANNEL_SYNC_CONNECTION', 'redis-channel-sync'),
            'queue' => env('QUEUE_NAME_CHANNEL_SYNC', 'channel-sync'),

            'window_minutes' => max(5, min(30, (int) env('CHANNEL_SYNC_WINDOW_MINUTES', 5))),

            'lease_seconds' => max(420, min(900, (int) env('CHANNEL_SYNC_LEASE_SECONDS', 420))),
            'job_timeout' => max(60, min(240, (int) env('CHANNEL_SYNC_JOB_TIMEOUT', 210))),

            'max_attempts' => max(1, min(8, (int) env('CHANNEL_SYNC_MAX_ATTEMPTS', 8))),
        ],

        'channel_order_recovery' => [
            'connection' => env('QUEUE_CHANNEL_ORDER_RECOVERY_CONNECTION', 'redis-channel-sync'),
            'queue' => env('QUEUE_NAME_CHANNEL_ORDER_RECOVERY', 'channel-order-recovery'),
            'parallelism' => max(1, min(4, (int) env('CHANNEL_ORDER_RECOVERY_PARALLELISM', 4))),
            'max_depth' => max(4, min(32, (int) env('CHANNEL_ORDER_RECOVERY_MAX_DEPTH', 8))),
            'max_memory_ratio' => max(0.50, min(0.90, (float) env('CHANNEL_ORDER_RECOVERY_MAX_MEMORY_RATIO', 0.70))),
            'window_minutes' => max(5, min(30, (int) env('CHANNEL_ORDER_RECOVERY_WINDOW_MINUTES', 5))),
            'lease_seconds' => max(420, min(900, (int) env('CHANNEL_ORDER_RECOVERY_LEASE_SECONDS', 600))),
            'job_timeout' => max(60, min(240, (int) env('CHANNEL_ORDER_RECOVERY_JOB_TIMEOUT', 210))),
            'max_attempts' => max(1, min(8, (int) env('CHANNEL_ORDER_RECOVERY_MAX_ATTEMPTS', 3))),
        ],

        'labels' => [
            'connection' => env('QUEUE_LABEL_CONNECTION', 'redis-long'),
            'queue' => env('QUEUE_NAME_LABELS', 'labels'),

            'parallelism' => max(1, min(6, (int) env('QUEUE_LABEL_PARALLELISM', 4))),
            'rate_limit_attempts' => (int) env('QUEUE_LABEL_RATE_LIMIT_ATTEMPTS', 5),
            'rate_limit_decay_seconds' => (int) env('QUEUE_LABEL_RATE_LIMIT_DECAY_SECONDS', 1),
        ],

        'label_awb' => [
            'connection' => env('QUEUE_LABEL_AWB_CONNECTION', 'redis-long'),
            'queue' => env('QUEUE_NAME_LABEL_AWB', 'label-awb'),

            'parallelism' => max(1, min(4, (int) env('QUEUE_LABEL_AWB_PARALLELISM', 2))),
        ],

        'label_awb_request' => [
            'connection' => env('QUEUE_LABEL_AWB_REQUEST_CONNECTION', 'redis-long'),
            'queues' => [
                'shopee' => env('QUEUE_NAME_LABEL_AWB_REQUEST_SHOPEE', 'label-awb-request-shopee'),
                'tiktok' => env('QUEUE_NAME_LABEL_AWB_REQUEST_TIKTOK', 'label-awb-request-tiktok'),
                'lazada' => env('QUEUE_NAME_LABEL_AWB_REQUEST_LAZADA', 'label-awb-request-lazada'),
            ],
            'parallelism' => max(1, min(3, (int) env('QUEUE_LABEL_AWB_REQUEST_PARALLELISM', 1))),
        ],

        'label_awb_poll' => [
            'connection' => env('QUEUE_LABEL_AWB_POLL_CONNECTION', 'redis-long'),
            'queues' => [
                'shopee' => env('QUEUE_NAME_LABEL_AWB_POLL_SHOPEE', 'label-awb-poll-shopee'),
                'tiktok' => env('QUEUE_NAME_LABEL_AWB_POLL_TIKTOK', 'label-awb-poll-tiktok'),
                'lazada' => env('QUEUE_NAME_LABEL_AWB_POLL_LAZADA', 'label-awb-poll-lazada'),
            ],
            'parallelism' => max(1, min(3, (int) env('QUEUE_LABEL_AWB_POLL_PARALLELISM', 1))),
            'max_attempts' => max(1, min(12, (int) env('QUEUE_LABEL_AWB_POLL_MAX_ATTEMPTS', 10))),
            'delays' => [2, 5, 10, 20, 30, 60],
        ],

        'label_download' => [
            'connection' => env('QUEUE_LABEL_DOWNLOAD_CONNECTION', 'redis-long'),
            'queues' => [
                'shopee' => env('QUEUE_NAME_LABEL_DOWNLOAD_SHOPEE', 'label-download-shopee'),
                'tiktok' => env('QUEUE_NAME_LABEL_DOWNLOAD_TIKTOK', 'label-download-tiktok'),
                'lazada' => env('QUEUE_NAME_LABEL_DOWNLOAD_LAZADA', 'label-download-lazada'),
            ],
            'parallelism' => max(1, min(3, (int) env('QUEUE_LABEL_DOWNLOAD_PARALLELISM', 1))),
            'max_jobs' => max(25, min(250, (int) env('QUEUE_LABEL_DOWNLOAD_MAX_JOBS', 100))),
            'max_time' => max(300, min(3600, (int) env('QUEUE_LABEL_DOWNLOAD_MAX_TIME', 1800))),
        ],

        'label_merge' => [
            'connection' => env('QUEUE_LABEL_MERGE_CONNECTION', 'redis-long'),
            'queue' => env('QUEUE_NAME_LABEL_MERGE', 'label-merge'),
            'parallelism' => max(1, min(2, (int) env('QUEUE_LABEL_MERGE_PARALLELISM', 1))),
            'max_jobs' => max(10, min(100, (int) env('QUEUE_LABEL_MERGE_MAX_JOBS', 25))),
            'max_time' => max(600, min(3600, (int) env('QUEUE_LABEL_MERGE_MAX_TIME', 1800))),
        ],

        'label_prefetch' => [
            'connection' => env('QUEUE_LABEL_CONNECTION', 'redis-long'),
            'queue' => env('QUEUE_NAME_LABEL_PREFETCH', 'label-prefetch'),
            'parallelism' => max(1, min(2, (int) env('QUEUE_LABEL_PREFETCH_PARALLELISM', 1))),
        ],

        'label_archive' => [
            'connection' => env('QUEUE_LABEL_ARCHIVE_CONNECTION', 'redis-long'),
            'queue' => env('QUEUE_NAME_LABEL_ARCHIVE', 'label-archive'),
            'parallelism' => max(1, min(3, (int) env('QUEUE_LABEL_ARCHIVE_PARALLELISM', 2))),
            'timeout' => max(60, min(600, (int) env('QUEUE_LABEL_ARCHIVE_TIMEOUT', 300))),
            'tries' => max(1, min(8, (int) env('QUEUE_LABEL_ARCHIVE_TRIES', 5))),
        ],

        'qr_labels' => [
            'connection' => env('QUEUE_QR_LABELS_CONNECTION', 'redis-long'),
            'queue' => env('QUEUE_NAME_QR_LABELS', 'qr-labels'),
            'memory_limit' => env('QUEUE_QR_LABELS_MEMORY_LIMIT', '512M'),
        ],

        'imports' => [
            'connection' => env('IMPORT_QUEUE_CONNECTION', 'redis-long'),
            'queue' => env('QUEUE_NAME_IMPORTS', 'imports'),
            'memory_limit' => env('IMPORT_MEMORY_LIMIT', '1024M'),
            'timeout' => (int) env('IMPORT_TIMEOUT', 1800),
        ],
    ],

];
