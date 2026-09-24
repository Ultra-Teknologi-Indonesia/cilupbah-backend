<?php

use App\Http\Middleware\HorizonBasicAuth;
use Illuminate\Support\Str;

$sameConnection = static function (string $pool, array $connections): string {
    $connections = array_values(array_unique(array_filter($connections)));

    if (count($connections) !== 1) {
        throw new LogicException(
            "Pool Horizon '{$pool}' hanya dapat menggabungkan queue pada satu koneksi Redis: "
            .implode(', ', $connections),
        );
    }

    return $connections[0];
};

$pool = static function (
    string $connection,
    array $queues,
    int $minProcesses,
    int $maxProcesses,
    int $timeout,
    int $memory,
    int $tries = 3,
    array $backoff = [5, 15, 30],
    int $nice = 0,
    int $maxTime = 1800,
    int $maxJobs = 100,
): array {
    $minProcesses = max(1, min(4, $minProcesses));
    $maxProcesses = max($minProcesses, min(12, $maxProcesses));

    return [
        'connection' => $connection,
        'queue' => array_values(array_unique($queues)),
        'balance' => 'auto',
        'autoScalingStrategy' => 'size',
        'minProcesses' => $minProcesses,
        'maxProcesses' => $maxProcesses,

        'balanceMaxShift' => max(1, min(3, (int) env('HORIZON_BALANCE_MAX_SHIFT', 2))),
        'balanceCooldown' => max(1, min(30, (int) env('HORIZON_BALANCE_COOLDOWN', 3))),
        'maxTime' => max(300, min(3600, $maxTime)),
        'maxJobs' => max(10, min(250, $maxJobs)),
        'timeout' => $timeout,
        'tries' => $tries,
        'backoff' => $backoff,
        'memory' => $memory,
        'nice' => $nice,
    ];
};

$q = static fn (string $key, string $default): string => (string) config("queue.names.{$key}", $default);
$route = static fn (string $key, string $field, string $default): string => (string) config("queue.routing.{$key}.{$field}", $default);

$orderSyncConnection = $sameConnection('order-sync', [
    $route('channel_sync', 'connection', 'redis-channel-sync'),
    $route('channel_order_recovery', 'connection', 'redis-channel-sync'),
]);
$stockUrgentConnection = $sameConnection('stock-urgent', [
    'redis',
    $route('stock_critical', 'connection', 'redis'),
    $route('warehouse_safety', 'connection', 'redis'),
    $route('channel_stock_critical', 'connection', 'redis'),
    $route('channel_stock_outbox', 'connection', 'redis'),
]);
$stockPropagationConnection = $sameConnection('stock-propagation', [
    $route('stock_default', 'connection', 'redis'),
    $route('channel_stock', 'connection', 'redis'),
    $route('channel_stock_normal', 'connection', 'redis'),
]);
$labelRenderConnection = $sameConnection('label-render', [
    $route('labels', 'connection', 'redis-long'),
    $route('label_merge', 'connection', 'redis-long'),
]);
$labelMaintenanceConnection = $sameConnection('label-maintenance', [
    $route('label_prefetch', 'connection', 'redis-long'),
    $route('label_archive', 'connection', 'redis-long'),
]);

$supervisors = [
    'supervisor-order-intake' => $pool(
        'redis',
        [$q('orders', 'orders'), $q('shopee_orders', 'shopee-orders'), $q('tiktok_orders', 'tiktok-orders'), $q('lazada_orders', 'lazada-orders'), $q('channel_order_refresh', 'channel-order-refresh')],
        1,
        max(4, min(10, (int) env('HORIZON_ORDER_INTAKE_MAX_PROCESSES', 6))),
        120,
        192,
        3,
        [5, 15, 30, 60],
        0,
        1800,
        250,
    ),
    'supervisor-order-sync-recovery' => $pool(
        $orderSyncConnection,
        [$route('channel_sync', 'queue', 'channel-sync'), $route('channel_order_recovery', 'queue', 'channel-order-recovery'), $q('product', 'product')],
        1,
        max(2, min(6, (int) env('HORIZON_ORDER_SYNC_MAX_PROCESSES', 4))),
        270,
        192,
        3,
        [10, 30, 60, 120],
        0,
        1800,
        100,
    ),
    'supervisor-fulfillment' => $pool(
        'redis',
        [$q('fulfillment', 'fulfillment'), $q('channel_fulfillment', 'channel-fulfillment'), $q('shopee_fulfillment', 'shopee-fulfillment'), $q('tiktok_fulfillment', 'tiktok-fulfillment'), $q('lazada_fulfillment', 'lazada-fulfillment'), $q('tiktok_packages', 'tiktok-packages')],
        1,
        max(3, min(8, (int) env('HORIZON_FULFILLMENT_MAX_PROCESSES', 5))),
        180,
        192,
        3,
        [10, 30, 60],
        0,
        1800,
        250,
    ),
    'supervisor-stock-urgent' => $pool(
        $stockUrgentConnection,
        [$q('stock_sync', 'stock-sync'), $route('stock_critical', 'queue', 'stock-critical'), $route('warehouse_safety', 'queue', 'warehouse-safety'), $route('channel_stock_critical', 'queue', 'channel-stock-critical'), $route('channel_stock_outbox', 'queue', 'channel-stock-outbox')],
        1,
        max(3, min(8, (int) env('HORIZON_STOCK_URGENT_MAX_PROCESSES', 4))),
        330,
        256,
        3,
        [3, 10, 30, 120],
        0,
        1800,
        100,
    ),
    'supervisor-stock-propagation' => $pool(
        $stockPropagationConnection,
        [$route('stock_default', 'queue', 'stock-default'), $route('channel_stock', 'queue', 'channel-stock'), $route('channel_stock_normal', 'queue', 'channel-stock-normal')],
        1,
        max(2, min(6, (int) env('HORIZON_STOCK_PROPAGATION_MAX_PROCESSES', 3))),
        330,
        256,
        3,
        [30, 120, 300],
        10,
        1800,
        100,
    ),
    'supervisor-marketplace-control' => $pool(
        'redis',
        [$q('channel_cancellation', 'channel-cancellation'), $q('shopee_cancellation', 'shopee-cancellation'), $q('tiktok_cancellation', 'tiktok-cancellation'), $q('lazada_cancellation', 'lazada-cancellation'), $q('tracking', 'tracking'), $q('shopee_tracking_events', 'shopee-tracking-events'), $q('shopee_tracking', 'shopee-tracking')],
        1,
        max(3, min(8, (int) env('HORIZON_MARKETPLACE_CONTROL_MAX_PROCESSES', 4))),
        180,
        192,
        3,
        [5, 15, 60],
        0,
        1800,
        250,
    ),
    'supervisor-marketplace-webhooks' => $pool(
        'redis',
        [$q('tiktok_webhooks', 'tiktok-webhooks'), $q('shopee_webhooks', 'shopee-webhooks'), $q('lazada_webhooks', 'lazada-webhooks')],
        1,
        max(4, min(10, (int) env('HORIZON_MARKETPLACE_WEBHOOK_MAX_PROCESSES', 5))),
        120,
        192,
        3,
        [10, 60, 300],
        0,
        1800,
        250,
    ),
    'supervisor-background-redis' => $pool(
        'redis',
        [env('WEBHOOK_QUEUE', 'webhooks'), 'default', 'notifications', $q('failed_jobs', 'failed-jobs'), $q('tiktok_aftersales', 'tiktok-aftersales'), $q('tiktok_catalog', 'tiktok-catalog'), $q('shopee_aftersales', 'shopee-aftersales'), $q('shopee_catalog', 'shopee-catalog'), $q('webhook_downloads', 'webhook-downloads'), $q('lazada_aftersales', 'lazada-aftersales'), $q('lazada_catalog', 'lazada-catalog')],
        1,
        max(2, min(6, (int) env('HORIZON_BACKGROUND_REDIS_MAX_PROCESSES', 3))),
        180,
        192,
        3,
        [30, 120, 300],
        10,
        1800,
        100,
    ),
    'supervisor-background-long' => $pool(
        $sameConnection('background-long', [$route('channel_product', 'connection', 'redis-long'), $route('channel_after_sales', 'connection', 'redis-long')]),
        [$route('channel_product', 'queue', 'channel-product'), $route('channel_after_sales', 'queue', 'channel-after-sales')],
        1,
        max(2, min(4, (int) env('HORIZON_BACKGROUND_LONG_MAX_PROCESSES', 2))),
        330,
        256,
        3,
        [30, 120, 300],
        10,
        1800,
        100,
    ),
    'supervisor-background-finance' => $pool(
        $route('channel_finance', 'connection', 'redis-finance'),
        [$route('channel_finance', 'queue', 'channel-finance')],
        1,
        1,
        240,
        256,
        5,
        [30, 120, 300, 900, 1800],
        10,
        1800,
        100,
    ),
    'supervisor-label-render' => $pool(
        $labelRenderConnection,
        [$route('labels', 'queue', 'labels'), $route('label_merge', 'queue', 'label-merge')],
        1,
        max(2, min(6, (int) env('HORIZON_LABEL_RENDER_MAX_PROCESSES', 3))),
        600,
        512,
        1,
        [10, 30, 60, 120],
        0,
        1800,
        50,
    ),
    'supervisor-label-download-shopee' => $pool(
        $route('label_download', 'connection', 'redis-long'),
        [$q('label_download_shopee', 'label-download-shopee')],
        1,
        max(2, min(4, (int) env('HORIZON_LABEL_DOWNLOAD_MAX_PROCESSES', 2))),
        180,
        256,
        3,
        [5, 15, 30, 60],
        5,
        1800,
        100,
    ),
    'supervisor-label-download-tiktok' => $pool(
        $route('label_download', 'connection', 'redis-long'),
        [$q('label_download_tiktok', 'label-download-tiktok')],
        1,
        max(2, min(4, (int) env('HORIZON_LABEL_DOWNLOAD_MAX_PROCESSES', 2))),
        180,
        256,
        3,
        [5, 15, 30, 60],
        5,
        1800,
        100,
    ),
    'supervisor-label-download-lazada' => $pool(
        $route('label_download', 'connection', 'redis-long'),
        [$q('label_download_lazada', 'label-download-lazada')],
        1,
        max(2, min(4, (int) env('HORIZON_LABEL_DOWNLOAD_MAX_PROCESSES', 2))),
        180,
        256,
        3,
        [5, 15, 30, 60],
        5,
        1800,
        100,
    ),
    'supervisor-label-maintenance' => $pool(
        $labelMaintenanceConnection,
        [$route('label_prefetch', 'queue', 'label-prefetch'), $route('label_archive', 'queue', 'label-archive')],
        1,
        1,
        300,
        256,
        5,
        [10, 30, 120, 300],
        10,
        1800,
        100,
    ),
    'supervisor-label-awb' => $pool(
        $route('label_awb', 'connection', 'redis-long'),
        [$route('label_awb', 'queue', 'label-awb')],
        1,
        1,
        180,
        256,
        3,
        [10, 30, 60],
        5,
        1800,
        100,
    ),
];

foreach (['shopee', 'tiktok', 'lazada'] as $channel) {
    $upper = strtoupper($channel);
    $requestConnection = (string) env('QUEUE_LABEL_AWB_REQUEST_CONNECTION', 'redis-long');
    $pollConnection = (string) env('QUEUE_LABEL_AWB_POLL_CONNECTION', 'redis-long');

    $supervisors["supervisor-label-awb-request-{$channel}"] = $pool(
        $requestConnection,
        [(string) env("QUEUE_NAME_LABEL_AWB_REQUEST_{$upper}", "label-awb-request-{$channel}")],
        1,
        max(2, min(4, (int) env('HORIZON_LABEL_AWB_REQUEST_MAX_PROCESSES', 2))),
        180,
        192,
        3,
        [5, 15, 30, 60],
        5,
        1800,
        100,
    );
    $supervisors["supervisor-label-awb-poll-{$channel}"] = $pool(
        $pollConnection,
        [(string) env("QUEUE_NAME_LABEL_AWB_POLL_{$upper}", "label-awb-poll-{$channel}")],
        1,
        max(1, min(3, (int) env('HORIZON_LABEL_AWB_POLL_MAX_PROCESSES', 1))),
        120,
        128,
        8,
        [2, 5, 15, 30, 60],
        5,
        1800,
        100,
    );
}

$supervisors += [
    'supervisor-maintenance-heavy' => $pool(
        $sameConnection('maintenance-heavy', [(string) config('operations.stock_cutover_console.queue_connection', 'redis-long'), (string) config('operations.order_cutover_console.queue_connection', 'redis-long'), $route('qr_labels', 'connection', 'redis-long')]),
        [(string) config('operations.stock_cutover_console.queue', 'stock-cutover'), (string) config('operations.order_cutover_console.queue', 'order-cutover'), $route('qr_labels', 'queue', 'qr-labels')],
        1,
        1,
        1800,
        1024,
        1,
        [60, 300, 900],
        10,
        1800,
        20,
    ),
    'supervisor-maintenance-downloads' => $pool(
        'redis-long',
        [$q('downloads', 'downloads')],
        1,
        1,
        900,
        256,
        3,
        [10, 30, 60],
        10,
        1800,
        100,
    ),
];

$supervisorProfiles = [
    'order-intake' => ['supervisor-order-intake', 'supervisor-order-sync-recovery'],
    'fulfillment' => ['supervisor-fulfillment'],
    'stock' => ['supervisor-stock-urgent', 'supervisor-stock-propagation'],
    'marketplace-ops' => ['supervisor-marketplace-control', 'supervisor-marketplace-webhooks'],
    'background' => ['supervisor-background-redis', 'supervisor-background-long', 'supervisor-background-finance'],
    'labels-pdf' => ['supervisor-label-render', 'supervisor-label-download-shopee', 'supervisor-label-download-tiktok', 'supervisor-label-download-lazada', 'supervisor-label-maintenance'],
    'labels-awb' => ['supervisor-label-awb', 'supervisor-label-awb-request-shopee', 'supervisor-label-awb-request-tiktok', 'supervisor-label-awb-request-lazada', 'supervisor-label-awb-poll-shopee', 'supervisor-label-awb-poll-tiktok', 'supervisor-label-awb-poll-lazada'],
    'maintenance' => ['supervisor-maintenance-heavy', 'supervisor-maintenance-downloads'],
];

$marketplaceLabelWaits = [];
foreach (['shopee', 'tiktok', 'lazada'] as $channel) {
    $upper = strtoupper($channel);
    $marketplaceLabelWaits[(string) env('QUEUE_LABEL_AWB_REQUEST_CONNECTION', 'redis-long').':'.(string) env("QUEUE_NAME_LABEL_AWB_REQUEST_{$upper}", "label-awb-request-{$channel}")] = 60;
    $marketplaceLabelWaits[(string) env('QUEUE_LABEL_AWB_POLL_CONNECTION', 'redis-long').':'.(string) env("QUEUE_NAME_LABEL_AWB_POLL_{$upper}", "label-awb-poll-{$channel}")] = 90;
    $marketplaceLabelWaits[(string) env('QUEUE_LABEL_DOWNLOAD_CONNECTION', 'redis-long').':'.$q("label_download_{$channel}", "label-download-{$channel}")] = 60;
}

return [
    'name' => env('HORIZON_NAME'),
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => env('HORIZON_REDIS_CONNECTION', 'default'),
    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'),
    'middleware' => ['web', HorizonBasicAuth::class],
    'allowed_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('HORIZON_ALLOWED_EMAILS', ''))))),
    'waits' => [
        ...$marketplaceLabelWaits,
        'redis:default' => 60,
        'redis:tracking' => 60,
        'redis:channel-cancellation' => 30,
        'redis:channel-fulfillment' => 60,
        'redis:channel-stock' => 120,
        'redis:'.$q('orders', 'orders') => 60,
        'redis:'.$q('fulfillment', 'fulfillment') => 60,
        'redis:'.$q('stock_sync', 'stock-sync') => 60,
        $orderSyncConnection.':'.$route('channel_sync', 'queue', 'channel-sync') => 120,
        $orderSyncConnection.':'.$route('channel_order_recovery', 'queue', 'channel-order-recovery') => 120,
        $labelRenderConnection.':'.$route('labels', 'queue', 'labels') => 60,
        $labelRenderConnection.':'.$route('label_merge', 'queue', 'label-merge') => 60,
        $labelMaintenanceConnection.':'.$route('label_prefetch', 'queue', 'label-prefetch') => 300,
        $labelMaintenanceConnection.':'.$route('label_archive', 'queue', 'label-archive') => 120,
        'redis-long:stock-cutover' => 300,
        'redis-long:order-cutover' => 300,
        'redis-long:qr-labels' => 300,
    ],
    'trim' => ['recent' => 15, 'pending' => 30, 'completed' => 15, 'recent_failed' => 720, 'failed' => 1440, 'monitored' => 720],
    'silenced' => [],
    'silenced_tags' => [],
    'metrics' => ['trim_snapshots' => ['job' => 24, 'queue' => 24]],
    'notifications' => ['slack_webhook' => env('HORIZON_SLACK_WEBHOOK'), 'slack_channel' => env('HORIZON_SLACK_CHANNEL'), 'mail' => env('HORIZON_MAIL_TO')],
    'fast_termination' => false,
    'memory_limit' => 192,
    'resident_process_budget_mb' => 128,
    'profile_memory_requests_mb' => [
        'order-intake' => 768,
        'fulfillment' => 512,
        'stock' => 768,
        'marketplace-ops' => 768,
        'background' => 1024,
        'labels-pdf' => 1536,
        'labels-awb' => 2048,
        'maintenance' => 768,
    ],
    'profile_memory_limits_mb' => [
        'order-intake' => 2560,
        'fulfillment' => 1536,
        'stock' => 2560,
        'marketplace-ops' => 2560,
        'background' => 2048,
        'labels-pdf' => 5120,
        'labels-awb' => 3072,
        'maintenance' => 2048,
    ],
    'profiles' => $supervisorProfiles,
    'active_profile' => env('HORIZON_PROFILE', 'all'),
    'defaults' => (function (array $definitions) use ($supervisorProfiles): array {
        $profile = strtolower(trim((string) env('HORIZON_PROFILE', 'all')));

        if ($profile === 'all') {
            return $definitions;
        }

        if (! array_key_exists($profile, $supervisorProfiles)) {
            throw new InvalidArgumentException("HORIZON_PROFILE '{$profile}' tidak dikenal.");
        }

        return array_intersect_key($definitions, array_flip($supervisorProfiles[$profile]));
    })($supervisors),
    'environments' => ['production' => [], 'staging' => [], 'local' => []],
    'watch' => ['app', 'bootstrap', 'config/**/*.php', 'database/**/*.php', 'public/**/*.php', 'resources/**/*.php', 'routes', 'composer.lock', 'composer.json', '.env'],
];
