<?php

return [
    /*
     * This feature can change a marketplace fulfillment state. It must remain
     * disabled until an owner explicitly enables both the feature and a shop.
     */
    'enabled' => (bool) env('SHIPPING_LABEL_PREFETCH_ENABLED', false),
    'allow_ready_to_ship' => (bool) env('SHIPPING_LABEL_PREFETCH_ALLOW_READY_TO_SHIP', false),

    'sources' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SHIPPING_LABEL_PREFETCH_SOURCES', '')),
    ))),
    'shop_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SHIPPING_LABEL_PREFETCH_SHOP_IDS', '')),
    ))),

    // Reuses the existing label-AWB supervisor. The manual queue is always first.
    'connection' => env('QUEUE_LABEL_PREFETCH_CONNECTION', 'redis-long'),
    'queue' => env('QUEUE_NAME_LABEL_PREFETCH', 'label-prefetch'),

    // One marketplace request globally every 15 seconds: no worker scale-up.
    'global_interval_seconds' => max(5, (int) env('SHIPPING_LABEL_PREFETCH_GLOBAL_INTERVAL_SECONDS', 15)),
    'reschedule_seconds' => max(5, (int) env('SHIPPING_LABEL_PREFETCH_RESCHEDULE_SECONDS', 30)),
    'manual_queue_pause_threshold' => max(0, (int) env('SHIPPING_LABEL_PREFETCH_MANUAL_QUEUE_PAUSE_THRESHOLD', 0)),
    'stale_processing_minutes' => max(5, (int) env('SHIPPING_LABEL_PREFETCH_STALE_PROCESSING_MINUTES', 15)),
    'dispatch_batch_size' => max(1, min(100, (int) env('SHIPPING_LABEL_PREFETCH_DISPATCH_BATCH_SIZE', 20))),
    'max_attempts' => max(1, min(8, (int) env('SHIPPING_LABEL_PREFETCH_MAX_ATTEMPTS', 5))),
    'retry_delays_seconds' => [60, 300, 900, 1800, 3600],
];
