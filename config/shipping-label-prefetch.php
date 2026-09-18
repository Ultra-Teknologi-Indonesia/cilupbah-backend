<?php

return [

    'enabled' => (bool) env('SHIPPING_LABEL_PREFETCH_ENABLED', true),

    'connection' => env('QUEUE_LABEL_CONNECTION', 'redis-long'),
    'queue' => env('QUEUE_NAME_LABEL_PREFETCH', 'label-prefetch'),

    'global_interval_seconds' => max(5, (int) env('SHIPPING_LABEL_PREFETCH_GLOBAL_INTERVAL_SECONDS', 15)),
    'reschedule_seconds' => max(5, (int) env('SHIPPING_LABEL_PREFETCH_RESCHEDULE_SECONDS', 30)),
    'manual_queue_pause_threshold' => max(0, (int) env('SHIPPING_LABEL_PREFETCH_MANUAL_QUEUE_PAUSE_THRESHOLD', 0)),
    'stale_processing_minutes' => max(5, (int) env('SHIPPING_LABEL_PREFETCH_STALE_PROCESSING_MINUTES', 15)),
    'dispatch_batch_size' => max(1, min(100, (int) env('SHIPPING_LABEL_PREFETCH_DISPATCH_BATCH_SIZE', 20))),
    'max_attempts' => max(1, min(8, (int) env('SHIPPING_LABEL_PREFETCH_MAX_ATTEMPTS', 5))),
    'retry_delays_seconds' => [60, 300, 900, 1800, 3600],
];
