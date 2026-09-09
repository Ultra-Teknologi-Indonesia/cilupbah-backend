<?php

return [
    'connection' => env('QUEUE_CHANNEL_FINANCE_CONNECTION', 'redis-finance'),
    'queue' => env('QUEUE_NAME_CHANNEL_FINANCE', 'channel-finance'),

    'max_queue_depth' => (int) env('FINANCE_SYNC_MAX_QUEUE_DEPTH', 5000),
    'max_redis_memory_ratio' => (float) env('FINANCE_SYNC_MAX_REDIS_MEMORY_RATIO', 0.80),

    'empty_response_retry_after_minutes' => (int) env('FINANCE_SYNC_EMPTY_RESPONSE_RETRY_MINUTES', 360),
    'incomplete_items_retry_after_minutes' => (int) env('FINANCE_SYNC_INCOMPLETE_ITEMS_RETRY_MINUTES', 15),
    'backpressure_retry_after_minutes' => (int) env('FINANCE_SYNC_BACKPRESSURE_RETRY_MINUTES', 5),
    'unexpected_retry_after_minutes' => (int) env('FINANCE_SYNC_UNEXPECTED_RETRY_MINUTES', 15),
    'stale_processing_minutes' => (int) env('FINANCE_SYNC_STALE_PROCESSING_MINUTES', 20),
    'dispatch_batch_size' => (int) env('FINANCE_SYNC_DISPATCH_BATCH_SIZE', 100),
    'health_warning_ratio' => (float) env('FINANCE_SYNC_HEALTH_WARNING_RATIO', 0.70),
    'health_critical_ratio' => (float) env('FINANCE_SYNC_HEALTH_CRITICAL_RATIO', 0.90),
];
