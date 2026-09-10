<?php

use App\Http\Middleware\HorizonBasicAuth;
use Illuminate\Support\Str;

return [

    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => env('HORIZON_REDIS_CONNECTION', 'default'),

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web', HorizonBasicAuth::class],

    'allowed_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('HORIZON_ALLOWED_EMAILS', ''))
    ))),

    'waits' => [
        'redis:default' => 60,
        'redis:tracking' => 60,
        'redis:channel-cancellation' => 30,
        'redis:channel-stock' => 60,
        config('queue.routing.stock_critical.connection', 'redis').':'
            .config('queue.routing.stock_critical.queue', 'stock-critical') => 30,
        config('queue.routing.stock_default.connection', 'redis').':'
            .config('queue.routing.stock_default.queue', 'stock-default') => 120,
        config('queue.routing.channel_finance.connection', 'redis-finance').':'
            .config('queue.routing.channel_finance.queue', 'channel-finance') => 120,
        'redis:channel-fulfillment' => 60,
        'redis-long:channel-product' => 120,
        'redis-long:channel-after-sales' => 120,
        'redis-long:stock-cutover' => 300,
        'redis-long:qr-labels' => 300,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 1440,
        'failed' => 2880,
        'monitored' => 1440,
    ],

    'silenced' => [

    ],

    'silenced_tags' => [

    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'notifications' => [
        'slack_webhook' => env('HORIZON_SLACK_WEBHOOK'),
        'slack_channel' => env('HORIZON_SLACK_CHANNEL'),
        'mail' => env('HORIZON_MAIL_TO'),
    ],

    'fast_termination' => false,

    'memory_limit' => 256,

    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default', 'notifications', env('WEBHOOK_QUEUE', 'webhooks'), 'failed-jobs'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 500,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],

        'supervisor-order-operations' => [
            'connection' => 'redis',
            'queue' => ['orders', 'fulfillment', 'stock-sync'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 4,
            'maxJobs' => 500,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [5, 15, 30],
            'memory' => 256,
            'nice' => 0,
        ],
        'supervisor-channel-sync' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_NAME_CHANNEL_SYNC', 'channel-sync'), env('QUEUE_NAME_PRODUCT', 'product')],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxJobs' => 500,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [5, 15, 30],
            'memory' => 128,
            'nice' => 0,
        ],

        'supervisor-channel-operations' => [
            'connection' => 'redis',
            'queue' => [
                env('QUEUE_NAME_CHANNEL_CANCELLATION', 'channel-cancellation'),
                env('QUEUE_NAME_CHANNEL_STOCK', 'channel-stock'),
                env('QUEUE_NAME_CHANNEL_FULFILLMENT', 'channel-fulfillment'),
            ],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'maxJobs' => 250,
            'maxTime' => 1800,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [10, 30, 60],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-channel-finance' => [
            'connection' => config('queue.routing.channel_finance.connection', 'redis-finance'),
            'queue' => [config('queue.routing.channel_finance.queue', 'channel-finance')],
            'balance' => 'simple',
            'minProcesses' => (int) env('HORIZON_FINANCE_MIN_PROCESSES', 1),
            'maxProcesses' => (int) env('HORIZON_FINANCE_MAX_PROCESSES', 2),
            'maxJobs' => 100,
            'maxTime' => 1800,
            'timeout' => 240,
            'tries' => 3,
            'backoff' => [30, 120, 300],
            'memory' => 256,
            'nice' => 5,
        ],
        'supervisor-channel-product' => [
            'connection' => config('queue.routing.channel_product.connection', 'redis-long'),
            'queue' => [config('queue.routing.channel_product.queue', 'channel-product')],
            'balance' => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxJobs' => 100,
            'maxTime' => 1800,
            'timeout' => 330,
            'tries' => 3,
            'backoff' => [30, 120, 300],
            'memory' => 256,
            // Product/catalog sync tidak boleh mengalahkan order dan webhook
            // saat node berada di bawah tekanan CPU.
            'nice' => 10,
        ],
        'supervisor-channel-after-sales' => [
            'connection' => config('queue.routing.channel_after_sales.connection', 'redis-long'),
            'queue' => [config('queue.routing.channel_after_sales.queue', 'channel-after-sales')],
            'balance' => 'simple',
            'minProcesses' => (int) env('HORIZON_AFTER_SALES_MIN_PROCESSES', 2),
            'maxProcesses' => (int) env('HORIZON_AFTER_SALES_MAX_PROCESSES', 3),
            'maxJobs' => 100,
            'maxTime' => 1800,
            'timeout' => 150,
            'tries' => 5,
            'backoff' => [30, 120, 300, 600, 1200],
            'memory' => 256,
            'nice' => 0,
        ],
        'supervisor-stock-cutover' => [
            'connection' => config('operations.stock_cutover_console.queue_connection', 'redis-long'),
            'queue' => [config('operations.stock_cutover_console.queue', 'stock-cutover')],
            'balance' => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxJobs' => 1,
            'maxTime' => 1800,
            'timeout' => 1800,
            'tries' => 1,
            'backoff' => [60, 300, 900],
            'memory' => 1024,
            'nice' => 10,
        ],
        'supervisor-stock' => [
            'connection' => config('queue.routing.stock_critical.connection', 'redis'),
            'queue' => [
                config('queue.routing.stock_critical.queue', 'stock-critical'),
                config('queue.routing.stock_default.queue', 'stock-default'),
            ],
            'balance' => 'simple',
            // Satu worker menjaga stock-critical tetap prioritas tanpa
            // menambah lonjakan CPU pada node produksi.
            'minProcesses' => max(1, (int) env('HORIZON_STOCK_MIN_PROCESSES', 1)),
            'maxProcesses' => max(
                1,
                (int) env('HORIZON_STOCK_MAX_PROCESSES', 1),
                (int) env('HORIZON_STOCK_MIN_PROCESSES', 1),
            ),
            'maxJobs' => 500,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [3, 10, 30],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-downloads' => [
            'connection' => 'redis-long',
            'queue' => ['downloads'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxJobs' => 100,
            'timeout' => 900,
            'tries' => 3,
            'backoff' => [10, 30, 60],
            'memory' => 256,
            'nice' => 0,
        ],
        'supervisor-labels' => [
            'connection' => config('queue.routing.labels.connection', 'redis-long'),
            'queue' => [config('queue.routing.labels.queue', 'labels')],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => max(1, (int) config('queue.routing.labels.parallelism', 2)),
            'maxJobs' => 100,
            'timeout' => 600,
            'tries' => 1,
            'memory' => 512,
            'nice' => 0,
        ],
        'supervisor-qr-labels' => [
            'connection' => config('queue.routing.qr_labels.connection', 'redis-long'),
            'queue' => [config('queue.routing.qr_labels.queue', 'qr-labels')],
            'balance' => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxJobs' => 20,
            'timeout' => 1800,
            'tries' => 1,
            'memory' => 512,
            'nice' => 5,
        ],
        'supervisor-tracking' => [
            'connection' => 'redis',
            'queue' => [config('queue.names.tracking', 'tracking')],
            'balance' => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxJobs' => 250,
            'timeout' => 120,
            'tries' => 2,
            'backoff' => [10, 60],
            'memory' => 128,
            'nice' => 5,
        ],
        'supervisor-tiktok-webhooks' => [
            'connection' => 'redis',
            'queue' => [
                env('QUEUE_NAME_TIKTOK_ORDERS', 'tiktok-orders'),
                env('QUEUE_NAME_TIKTOK_PACKAGES', 'tiktok-packages'),
                env('QUEUE_NAME_TIKTOK_WEBHOOKS', 'tiktok-webhooks'),
                env('QUEUE_NAME_TIKTOK_AFTERSALES', 'tiktok-aftersales'),
                env('QUEUE_NAME_TIKTOK_CATALOG', 'tiktok-catalog'),
            ],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 4,
            'maxJobs' => 500,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 256,
            'nice' => 0,
        ],
        'supervisor-shopee-webhooks' => [
            'connection' => 'redis',
            'queue' => [
                env('QUEUE_NAME_SHOPEE_ORDERS', 'shopee-orders'),
                env('QUEUE_NAME_SHOPEE_TRACKING', 'shopee-tracking'),
                env('QUEUE_NAME_SHOPEE_WEBHOOKS', 'shopee-webhooks'),
                env('QUEUE_NAME_SHOPEE_AFTERSALES', 'shopee-aftersales'),
                env('QUEUE_NAME_SHOPEE_CATALOG', 'shopee-catalog'),
                env('QUEUE_NAME_WEBHOOK_DOWNLOADS', 'webhook-downloads'),
            ],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 4,
            'maxJobs' => 500,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 256,
            'nice' => 0,
        ],
        'supervisor-lazada-webhooks' => [
            'connection' => 'redis',
            'queue' => [
                env('QUEUE_NAME_LAZADA_ORDERS', 'lazada-orders'),
                env('QUEUE_NAME_LAZADA_FULFILLMENT', 'lazada-fulfillment'),
                env('QUEUE_NAME_LAZADA_WEBHOOKS', 'lazada-webhooks'),
                env('QUEUE_NAME_LAZADA_AFTERSALES', 'lazada-aftersales'),
                env('QUEUE_NAME_LAZADA_CATALOG', 'lazada-catalog'),
            ],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'maxJobs' => 500,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 256,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-default' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-order-operations' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-channel-sync' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-channel-operations' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-channel-finance' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-channel-after-sales' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-stock-cutover' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-stock' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-downloads' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-labels' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-qr-labels' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-tracking' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-tiktok-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 2,
                'memory' => 256,
            ],
            'supervisor-shopee-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 2,
                'memory' => 256,
            ],
            'supervisor-lazada-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 2,
                'memory' => 256,
            ],
        ],

        'staging' => [
            'supervisor-default' => [
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 5,
            ],
            'supervisor-order-operations' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-channel-sync' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-channel-operations' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-stock-cutover' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-stock' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-downloads' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-labels' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-qr-labels' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-tracking' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-tiktok-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 3,
            ],
            'supervisor-shopee-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 3,
            ],
            'supervisor-lazada-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
        ],

        'local' => [
            'supervisor-default' => [
                'maxProcesses' => 3,
            ],
            'supervisor-order-operations' => [
                'minProcesses' => 1,
                'maxProcesses' => 3,
            ],
            'supervisor-channel-sync' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-channel-operations' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-stock-cutover' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-stock' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-downloads' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-labels' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-qr-labels' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-tracking' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-tiktok-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-shopee-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-lazada-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
