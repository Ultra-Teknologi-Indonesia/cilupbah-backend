<?php

use App\Http\Middleware\HorizonBasicAuth;
use Illuminate\Support\Str;

$supervisorProfiles = [

    'critical' => [
        'supervisor-orders',
        'supervisor-fulfillment',
        'supervisor-stock-sync',
        'supervisor-channel-cancellation',
        'supervisor-channel-fulfillment',
        'supervisor-shopee-cancellation',
        'supervisor-tiktok-cancellation',
        'supervisor-lazada-cancellation',
        'supervisor-shopee-fulfillment',
        'supervisor-tiktok-fulfillment',
        'supervisor-lazada-fulfillment',
        'supervisor-stock',
        'supervisor-stock-default',
        'supervisor-warehouse-safety',
        'supervisor-tracking',
        'supervisor-shopee-orders',
        'supervisor-tiktok-orders',
        'supervisor-lazada-orders',
        'supervisor-channel-order-refresh',
        'supervisor-tiktok-webhooks-operational',
        'supervisor-tiktok-packages',
        'supervisor-shopee-webhooks-operational',
        'supervisor-shopee-tracking-ingress',
        'supervisor-shopee-tracking',
        'supervisor-lazada-webhooks-operational',
    ],

    'background' => [
        'supervisor-default',
        'supervisor-channel-sync',
        'supervisor-channel-stock',
        'supervisor-channel-stock-critical',
        'supervisor-channel-stock-normal',
        'supervisor-channel-stock-outbox',
        'supervisor-product-validation',
        'supervisor-channel-finance',
        'supervisor-channel-product',
        'supervisor-channel-after-sales',
        'supervisor-cutover',
        'supervisor-downloads',
        'supervisor-qr-labels',
        'supervisor-tiktok-webhooks-background',
        'supervisor-shopee-webhooks-background',
        'supervisor-lazada-webhooks-background',
    ],

    'labels-pdf' => [
        'supervisor-labels',
        'supervisor-label-merge',
        'supervisor-label-download-shopee',
        'supervisor-label-download-tiktok',
        'supervisor-label-download-lazada',
    ],

    'labels-prefetch' => [
        'supervisor-label-prefetch',
    ],

    'labels-awb' => [
        'supervisor-label-awb',
        'supervisor-label-awb-request-shopee',
        'supervisor-label-awb-request-tiktok',
        'supervisor-label-awb-request-lazada',
        'supervisor-label-awb-poll-shopee',
        'supervisor-label-awb-poll-tiktok',
        'supervisor-label-awb-poll-lazada',
    ],

    'labels-archive' => [
        'supervisor-label-archive',
    ],

];

$legacyOrderOperationsProcesses = max(
    1,
    min(6, (int) env('HORIZON_ORDER_OPERATIONS_PROCESSES', 6)),
);
$ordersProcesses = max(
    1,
    min(4, (int) env('HORIZON_ORDERS_PROCESSES', min(3, $legacyOrderOperationsProcesses))),
);
$fulfillmentProcesses = max(
    1,
    min(3, (int) env('HORIZON_FULFILLMENT_PROCESSES', min(2, $legacyOrderOperationsProcesses))),
);
$stockSyncProcesses = max(
    1,
    min(2, (int) env('HORIZON_STOCK_SYNC_PROCESSES', 1)),
);
$stockMaxProcesses = max(
    1,
    min(4, (int) env('HORIZON_STOCK_MAX_PROCESSES', 4)),
);
$channelStockMaxProcesses = max(
    1,
    min(2, (int) env('HORIZON_CHANNEL_STOCK_MAX_PROCESSES', 1)),
);
$channelStockCriticalProcesses = max(
    1,
    min(2, (int) env('HORIZON_CHANNEL_STOCK_CRITICAL_PROCESSES', 1)),
);
$channelStockNormalProcesses = max(
    1,
    min(2, (int) env('HORIZON_CHANNEL_STOCK_NORMAL_PROCESSES', 1)),
);
$channelCancellationProcesses = max(
    1,
    min(4, (int) env('HORIZON_CHANNEL_CANCELLATION_PROCESSES', 2)),
);
$channelOrderRefreshProcesses = max(
    1,
    min(2, (int) env('HORIZON_CHANNEL_ORDER_REFRESH_PROCESSES', 1)),
);

$dedicatedQueueSupervisor = static function (
    string $connection,
    string $queue,
    int $timeout,
    int $memory,
    array $backoff,
    int $tries = 3,
    int $nice = 0,
): array {
    return [
        'connection' => $connection,
        'queue' => [$queue],
        'balance' => 'off',
        'minProcesses' => 1,
        'maxProcesses' => 1,
        'maxTime' => 3600,
        'maxJobs' => 100,
        'timeout' => $timeout,
        'tries' => $tries,
        'backoff' => $backoff,
        'memory' => $memory,
        'nice' => $nice,
    ];
};

$dedicatedQueueSupervisors = [];
foreach (['shopee', 'tiktok', 'lazada'] as $channel) {
    $dedicatedQueueSupervisors["supervisor-{$channel}-cancellation"] = $dedicatedQueueSupervisor(
        'redis',
        env('QUEUE_NAME_'.strtoupper($channel).'_CANCELLATION', "{$channel}-cancellation"),
        90,
        128,
        [10, 30, 60],
        5,
    );

    $dedicatedQueueSupervisors["supervisor-{$channel}-fulfillment"] = $dedicatedQueueSupervisor(
        'redis',
        env('QUEUE_NAME_'.strtoupper($channel).'_FULFILLMENT', "{$channel}-fulfillment"),
        120,
        128,
        [10, 30, 60],
        3,
    );

    $dedicatedQueueSupervisors["supervisor-label-awb-request-{$channel}"] = $dedicatedQueueSupervisor(
        env('QUEUE_LABEL_AWB_REQUEST_CONNECTION', 'redis-long'),
        env('QUEUE_NAME_LABEL_AWB_REQUEST_'.strtoupper($channel), "label-awb-request-{$channel}"),
        180,
        192,
        [5, 15, 30, 60],
        3,
        5,
    );

    $dedicatedQueueSupervisors["supervisor-label-awb-poll-{$channel}"] = $dedicatedQueueSupervisor(
        env('QUEUE_LABEL_AWB_POLL_CONNECTION', 'redis-long'),
        env('QUEUE_NAME_LABEL_AWB_POLL_'.strtoupper($channel), "label-awb-poll-{$channel}"),
        120,
        128,
        [2, 5, 15, 30, 60],
        8,
        5,
    );

    $dedicatedQueueSupervisors["supervisor-label-download-{$channel}"] = $dedicatedQueueSupervisor(
        env('QUEUE_LABEL_DOWNLOAD_CONNECTION', 'redis-long'),
        env('QUEUE_NAME_LABEL_DOWNLOAD_'.strtoupper($channel), "label-download-{$channel}"),
        180,
        256,
        [5, 15, 30, 60],
        3,
        5,
    );
}

$dedicatedQueueSupervisors['supervisor-label-merge'] = $dedicatedQueueSupervisor(
    env('QUEUE_LABEL_MERGE_CONNECTION', 'redis-long'),
    env('QUEUE_NAME_LABEL_MERGE', 'label-merge'),
    600,
    512,
    [10, 30, 60, 120],
    3,
);

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
        config('queue.routing.warehouse_safety.connection', 'redis').':'
            .config('queue.routing.warehouse_safety.queue', 'warehouse-safety') => 30,
        config('queue.routing.channel_stock.connection', 'redis').':'
            .config('queue.routing.channel_stock.queue', 'channel-stock') => 120,
        config('queue.routing.channel_stock_critical.connection', 'redis').':'
            .config('queue.routing.channel_stock_critical.queue', 'channel-stock-critical') => 30,
        config('queue.routing.channel_stock_normal.connection', 'redis').':'
            .config('queue.routing.channel_stock_normal.queue', 'channel-stock-normal') => 120,
        config('queue.routing.channel_stock_outbox.connection', 'redis').':'
            .config('queue.routing.channel_stock_outbox.queue', 'channel-stock-outbox') => 120,
        config('queue.routing.channel_finance.connection', 'redis-finance').':'
            .config('queue.routing.channel_finance.queue', 'channel-finance') => 120,
        config('queue.routing.channel_sync.connection', 'redis-channel-sync').':'
            .config('queue.routing.channel_sync.queue', 'channel-sync') => 120,
        config('queue.routing.channel_sync.connection', 'redis-channel-sync').':'
            .config('queue.names.product', 'product') => 120,
        'redis:channel-fulfillment' => 60,
        'redis:'.config('queue.names.orders', 'orders') => 60,
        'redis:'.config('queue.names.fulfillment', 'fulfillment') => 60,
        'redis:'.config('queue.names.stock_sync', 'stock-sync') => 60,
        'redis-long:channel-product' => 120,
        'redis-long:channel-after-sales' => 120,
        'redis-long:stock-cutover' => 300,
        'redis-long:order-cutover' => 300,
        'redis-long:qr-labels' => 300,
        config('queue.routing.label_archive.connection', 'redis-long').':'
            .config('queue.routing.label_archive.queue', 'label-archive') => 120,
        config('queue.routing.label_awb.connection', 'redis-long').':'
            .config('queue.routing.label_awb.queue', 'label-awb') => 60,
        config('queue.routing.label_prefetch.connection', 'redis-long').':'
            .config('queue.routing.label_prefetch.queue', 'label-prefetch') => 300,
        'redis:shopee-tracking-events' => 30,
        'redis:shopee-tracking' => 60,
    ],

    'trim' => [

        'recent' => 15,
        'pending' => 30,
        'completed' => 15,
        'recent_failed' => 720,
        'failed' => 1440,
        'monitored' => 720,
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

    'memory_limit' => 192,

    'profiles' => $supervisorProfiles,

    'active_profile' => env('HORIZON_PROFILE', 'all'),

    'defaults' => (function (array $supervisors) use ($supervisorProfiles): array {
        $profile = strtolower(trim((string) env('HORIZON_PROFILE', 'all')));

        if ($profile === 'all') {
            return $supervisors;
        }

        if (! array_key_exists($profile, $supervisorProfiles)) {
            throw new InvalidArgumentException(
                "HORIZON_PROFILE '{$profile}' tidak dikenal. Gunakan all, critical, background, labels-pdf, labels-prefetch, labels-awb, atau labels-archive."
            );
        }

        $allowed = array_flip($supervisorProfiles[$profile]);

        return array_intersect_key($supervisors, $allowed);
    })([
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => [env('WEBHOOK_QUEUE', 'webhooks'), 'default', 'notifications', 'failed-jobs'],

            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 250,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],

        'supervisor-orders' => [
            'connection' => 'redis',
            'queue' => [config('queue.names.orders', 'orders')],
            'balance' => 'off',
            'minProcesses' => $ordersProcesses,
            'maxProcesses' => $ordersProcesses,
            'maxTime' => 3600,
            'maxJobs' => 250,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [5, 15, 30],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-fulfillment' => [
            'connection' => 'redis',
            'queue' => [config('queue.names.fulfillment', 'fulfillment')],
            'balance' => 'off',
            'minProcesses' => $fulfillmentProcesses,
            'maxProcesses' => $fulfillmentProcesses,
            'maxTime' => 3600,
            'maxJobs' => 250,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [5, 15, 30],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-stock-sync' => [
            'connection' => 'redis',
            'queue' => [config('queue.names.stock_sync', 'stock-sync')],
            'balance' => 'off',
            'minProcesses' => $stockSyncProcesses,
            'maxProcesses' => $stockSyncProcesses,
            'maxTime' => 3600,
            'maxJobs' => 250,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [5, 15, 30],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-channel-sync' => [
            'connection' => config('queue.routing.channel_sync.connection', 'redis-channel-sync'),
            'queue' => [env('QUEUE_NAME_CHANNEL_SYNC', 'channel-sync')],
            'balance' => 'off',

            'minProcesses' => 4,
            'maxProcesses' => 4,
            'maxTime' => 3600,
            'maxJobs' => 250,
            'timeout' => 270,
            'tries' => 1,
            'backoff' => [5, 15, 30],
            'memory' => 192,
            'nice' => 0,
        ],
        'supervisor-product-validation' => [
            'connection' => config('queue.routing.channel_sync.connection', 'redis-channel-sync'),
            'queue' => [env('QUEUE_NAME_PRODUCT', 'product')],
            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 1800,
            'maxJobs' => 250,
            'timeout' => 180,
            'tries' => 3,
            'backoff' => [30, 120, 300],
            'memory' => 192,
            'nice' => 10,
        ],

        'supervisor-channel-cancellation' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_NAME_CHANNEL_CANCELLATION', 'channel-cancellation')],
            'balance' => 'off',
            'minProcesses' => $channelCancellationProcesses,
            'maxProcesses' => $channelCancellationProcesses,
            'maxJobs' => 250,
            'maxTime' => 1800,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [10, 30, 60],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-channel-fulfillment' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_NAME_CHANNEL_FULFILLMENT', 'channel-fulfillment')],
            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxJobs' => 250,
            'maxTime' => 1800,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [10, 30, 60],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-channel-stock' => [
            'connection' => config('queue.routing.channel_stock.connection', 'redis'),
            'queue' => [config('queue.routing.channel_stock.queue', 'channel-stock')],
            'balance' => 'off',
            'minProcesses' => $channelStockMaxProcesses,
            'maxProcesses' => $channelStockMaxProcesses,
            'maxJobs' => 100,
            'maxTime' => 1800,
            'timeout' => 330,
            'tries' => 3,
            'backoff' => [30, 120, 300],
            'memory' => 256,
            'nice' => 10,
        ],
        'supervisor-channel-stock-critical' => [
            'connection' => config('queue.routing.channel_stock_critical.connection', 'redis'),
            'queue' => [config('queue.routing.channel_stock_critical.queue', 'channel-stock-critical')],
            'balance' => 'off',
            'minProcesses' => $channelStockCriticalProcesses,
            'maxProcesses' => $channelStockCriticalProcesses,
            'maxJobs' => 100,
            'maxTime' => 1800,
            'timeout' => 330,
            'tries' => 1,
            'memory' => 256,
            'nice' => 10,
        ],
        'supervisor-channel-stock-normal' => [
            'connection' => config('queue.routing.channel_stock_normal.connection', 'redis'),

            'queue' => [
                config('queue.routing.channel_stock_normal.queue', 'channel-stock-normal'),
            ],
            'balance' => 'off',
            'minProcesses' => $channelStockNormalProcesses,
            'maxProcesses' => $channelStockNormalProcesses,
            'maxJobs' => 100,
            'maxTime' => 1800,
            'timeout' => 330,
            'tries' => 1,
            'memory' => 256,
            'nice' => 10,
        ],
        'supervisor-channel-stock-outbox' => [
            'connection' => config('queue.routing.channel_stock_outbox.connection', 'redis'),
            'queue' => [config('queue.routing.channel_stock_outbox.queue', 'channel-stock-outbox')],
            'balance' => 'off',
            'minProcesses' => max(1, min(2, (int) env('HORIZON_CHANNEL_STOCK_OUTBOX_PROCESSES', 1))),
            'maxProcesses' => max(1, min(2, (int) env('HORIZON_CHANNEL_STOCK_OUTBOX_PROCESSES', 1))),
            'maxJobs' => 250,
            'maxTime' => 1800,
            'timeout' => 30,
            'tries' => 3,
            'backoff' => [1, 5, 15],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-channel-finance' => [
            'connection' => config('queue.routing.channel_finance.connection', 'redis-finance'),
            'queue' => [config('queue.routing.channel_finance.queue', 'channel-finance')],
            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxJobs' => 100,
            'maxTime' => 1800,
            'timeout' => 240,
            'tries' => 5,
            'backoff' => [30, 120, 300, 900, 1800],
            'memory' => 256,
            'nice' => 5,
        ],
        'supervisor-channel-product' => [
            'connection' => config('queue.routing.channel_product.connection', 'redis-long'),
            'queue' => [config('queue.routing.channel_product.queue', 'channel-product')],
            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxJobs' => 100,
            'maxTime' => 1800,
            'timeout' => 330,
            'tries' => 3,
            'backoff' => [30, 120, 300],
            'memory' => 256,
            'nice' => 10,
        ],
        'supervisor-channel-after-sales' => [
            'connection' => config('queue.routing.channel_after_sales.connection', 'redis-long'),
            'queue' => [config('queue.routing.channel_after_sales.queue', 'channel-after-sales')],
            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxJobs' => 100,
            'maxTime' => 1800,
            'timeout' => 150,
            'tries' => 5,
            'backoff' => [30, 120, 300, 600, 1200],
            'memory' => 256,
            'nice' => 0,
        ],
        'supervisor-cutover' => [
            'connection' => config('operations.stock_cutover_console.queue_connection', 'redis-long'),

            'queue' => [
                config('operations.stock_cutover_console.queue', 'stock-cutover'),
                config('operations.order_cutover_console.queue', 'order-cutover'),
            ],
            'balance' => 'off',
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

            'queue' => [config('queue.routing.stock_critical.queue', 'stock-critical')],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'minProcesses' => 1,
            'maxProcesses' => $stockMaxProcesses,
            'maxTime' => 3600,
            'maxJobs' => 250,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [3, 10, 30],
            'memory' => 128,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 5,
            'nice' => 0,
        ],
        'supervisor-stock-default' => [
            'connection' => config('queue.routing.stock_default.connection', 'redis'),

            'queue' => [config('queue.routing.stock_default.queue', 'stock-default')],
            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 250,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [3, 10, 30],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-warehouse-safety' => [
            'connection' => config('queue.routing.warehouse_safety.connection', 'redis'),
            'queue' => [config('queue.routing.warehouse_safety.queue', 'warehouse-safety')],
            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxJobs' => 250,
            'maxTime' => 3600,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [3, 10, 30],
            'memory' => 128,

            'nice' => 0,
        ],
        'supervisor-downloads' => [
            'connection' => 'redis-long',
            'queue' => ['downloads'],
            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => 1,
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
            'balance' => 'off',
            'minProcesses' => config('queue.routing.labels.parallelism', 4),
            'maxProcesses' => config('queue.routing.labels.parallelism', 4),
            'maxJobs' => 100,
            'timeout' => 600,
            'tries' => 1,
            'memory' => 512,
            'nice' => 0,
        ],
        'supervisor-label-prefetch' => [
            'connection' => config('queue.routing.label_prefetch.connection', 'redis-long'),
            'queue' => [config('queue.routing.label_prefetch.queue', 'label-prefetch')],
            'balance' => 'off',
            'minProcesses' => config('queue.routing.label_prefetch.parallelism', 1),
            'maxProcesses' => config('queue.routing.label_prefetch.parallelism', 1),
            'maxJobs' => 100,
            'timeout' => 180,
            'tries' => 3,
            'backoff' => [10, 30, 60],
            'memory' => 256,
            'nice' => 5,
        ],
        'supervisor-label-awb' => [
            'connection' => config('queue.routing.label_awb.connection', 'redis-long'),

            'queue' => [config('queue.routing.label_awb.queue', 'label-awb')],
            'balance' => 'off',
            'minProcesses' => config('queue.routing.label_awb.parallelism', 2),
            'maxProcesses' => config('queue.routing.label_awb.parallelism', 2),
            'maxJobs' => 100,
            'timeout' => 180,
            'tries' => 3,
            'backoff' => [10, 30, 60],
            'memory' => 256,
            'nice' => 5,
        ],
        'supervisor-label-archive' => [
            'connection' => config('queue.routing.label_archive.connection', 'redis-long'),
            'queue' => [config('queue.routing.label_archive.queue', 'label-archive')],
            'balance' => 'off',

            'minProcesses' => config('queue.routing.label_archive.parallelism', 2),
            'maxProcesses' => config('queue.routing.label_archive.parallelism', 2),
            'maxTime' => 1800,
            'maxJobs' => 100,
            'timeout' => config('queue.routing.label_archive.timeout', 300),
            'tries' => config('queue.routing.label_archive.tries', 5),
            'backoff' => [10, 30, 120, 300],
            'memory' => 128,
            'nice' => 10,
        ],
        'supervisor-qr-labels' => [
            'connection' => config('queue.routing.qr_labels.connection', 'redis-long'),
            'queue' => [config('queue.routing.qr_labels.queue', 'qr-labels')],
            'balance' => 'off',
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
            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxJobs' => 250,
            'timeout' => 120,
            'tries' => 2,
            'backoff' => [10, 60],
            'memory' => 128,
            'nice' => 5,
        ],

        'supervisor-shopee-orders' => [
            'connection' => 'redis',
            'queue' => [config('queue.names.shopee_orders', 'shopee-orders')],

            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'minProcesses' => 2,
            'maxProcesses' => 4,
            'maxJobs' => 250,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 128,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 5,
            'nice' => 0,
        ],
        'supervisor-tiktok-orders' => [
            'connection' => 'redis',
            'queue' => [config('queue.names.tiktok_orders', 'tiktok-orders')],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'minProcesses' => 2,

            'maxProcesses' => 4,
            'maxJobs' => 250,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 128,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 5,
            'nice' => 0,
        ],
        'supervisor-lazada-orders' => [
            'connection' => 'redis',
            'queue' => [config('queue.names.lazada_orders', 'lazada-orders')],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxJobs' => 250,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 128,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 5,
            'nice' => 0,
        ],
        'supervisor-channel-order-refresh' => [
            'connection' => 'redis',
            'queue' => [config('queue.names.channel_order_refresh', 'channel-order-refresh')],
            'balance' => 'off',
            'minProcesses' => $channelOrderRefreshProcesses,
            'maxProcesses' => $channelOrderRefreshProcesses,
            'maxTime' => 1800,
            'maxJobs' => 250,
            'timeout' => 120,
            'tries' => 8,
            'backoff' => [2, 5, 15, 30, 60, 120],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-tiktok-webhooks-operational' => [
            'connection' => 'redis',

            'queue' => [env('QUEUE_NAME_TIKTOK_WEBHOOKS', 'tiktok-webhooks')],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'minProcesses' => 2,
            'maxProcesses' => 3,
            'maxJobs' => 250,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 128,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 5,
            'nice' => 0,
        ],
        'supervisor-tiktok-packages' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_NAME_TIKTOK_PACKAGES', 'tiktok-packages')],
            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxJobs' => 250,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-tiktok-webhooks-background' => [
            'connection' => 'redis',
            'queue' => [
                env('QUEUE_NAME_TIKTOK_AFTERSALES', 'tiktok-aftersales'),
                env('QUEUE_NAME_TIKTOK_CATALOG', 'tiktok-catalog'),
            ],
            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxJobs' => 250,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 128,
            'nice' => 5,
        ],
        'supervisor-shopee-webhooks-operational' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_NAME_SHOPEE_WEBHOOKS', 'shopee-webhooks')],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'minProcesses' => 2,
            'maxProcesses' => 2,
            'maxJobs' => 250,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 128,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 5,
            'nice' => 0,
        ],
        'supervisor-shopee-tracking' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_NAME_SHOPEE_TRACKING', 'shopee-tracking')],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',

            'minProcesses' => 2,
            'maxProcesses' => 3,
            'maxJobs' => 250,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 128,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'nice' => 0,
        ],

        'supervisor-shopee-tracking-ingress' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_NAME_SHOPEE_TRACKING_EVENTS', 'shopee-tracking-events')],
            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 250,
            'timeout' => 30,
            'tries' => 3,
            'backoff' => [5, 15, 60],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-shopee-webhooks-background' => [
            'connection' => 'redis',
            'queue' => [
                env('QUEUE_NAME_SHOPEE_AFTERSALES', 'shopee-aftersales'),
                env('QUEUE_NAME_SHOPEE_CATALOG', 'shopee-catalog'),
                env('QUEUE_NAME_WEBHOOK_DOWNLOADS', 'webhook-downloads'),
            ],
            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxJobs' => 250,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 128,
            'nice' => 5,
        ],
        'supervisor-lazada-webhooks-operational' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_NAME_LAZADA_WEBHOOKS', 'lazada-webhooks')],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxJobs' => 250,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 128,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 5,
            'nice' => 0,
        ],
        'supervisor-lazada-fulfillment' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_NAME_LAZADA_FULFILLMENT', 'lazada-fulfillment')],
            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxJobs' => 250,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-lazada-webhooks-background' => [
            'connection' => 'redis',
            'queue' => [
                env('QUEUE_NAME_LAZADA_AFTERSALES', 'lazada-aftersales'),
                env('QUEUE_NAME_LAZADA_CATALOG', 'lazada-catalog'),
            ],
            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxJobs' => 250,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 128,
            'nice' => 5,
        ],
        ...$dedicatedQueueSupervisors,
    ]),

    'environments' => [

        'production' => [],
        'staging' => [],
        'local' => [],
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
