<?php

return [
    'name' => 'Channel',
    'api_rate_limit_per_second' => (int) env('CHANNEL_API_RATE_LIMIT_PER_SECOND', 8),

    'auto_push_product_content' => (bool) env('CHANNEL_AUTO_PUSH_PRODUCT_CONTENT', false),

    'lazada_video_enabled' => (bool) env('LAZADA_VIDEO_ENABLED', true),

    'shopee_max_models' => (int) env('SHOPEE_MAX_MODELS', 50),

    'download_retry_attempts' => (int) env('CHANNEL_DOWNLOAD_RETRY_ATTEMPTS', 4),
    'download_max_pages' => (int) env('CHANNEL_DOWNLOAD_MAX_PAGES', 10000),

    'product_sync_overlap_lock_seconds' => (int) env('CHANNEL_PRODUCT_SYNC_OVERLAP_LOCK_SECONDS', 360),

    'stock_sync_dispatch_window_seconds' => (int) env('CHANNEL_STOCK_SYNC_DISPATCH_WINDOW_SECONDS', 50),
    // Keep Redis prefetch close to worker capacity. PostgreSQL remains the
    // durable outbox, so a small claim batch is safer than preloading jobs.
    'stock_sync_dispatch_claim_limit' => max(1, min(100, (int) env('CHANNEL_STOCK_SYNC_DISPATCH_CLAIM_LIMIT', 20))),
    // Bound marketplace mutations per shop independently from global workers.
    'stock_sync_max_inflight_per_shop' => max(1, min(4, (int) env('CHANNEL_STOCK_SYNC_MAX_INFLIGHT_PER_SHOP', 1))),
    'stock_sync_lease_seconds' => (int) env('CHANNEL_STOCK_SYNC_LEASE_SECONDS', 600),
    'stock_sync_max_attempts' => (int) env('CHANNEL_STOCK_SYNC_MAX_ATTEMPTS', 12),
    'stock_sync_retry_backoff' => [60, 300, 900, 1800],

    'search_cache_ttl_seconds' => (int) env('CHANNEL_SEARCH_CACHE_TTL_SECONDS', 30),
    'lazada_search_index_max_pages' => (int) env('LAZADA_SEARCH_INDEX_MAX_PAGES', 10000),

    'search_remote_timeout_seconds' => (int) env('CHANNEL_SEARCH_REMOTE_TIMEOUT_SECONDS', 10),
    'search_remote_attempts' => (int) env('CHANNEL_SEARCH_REMOTE_ATTEMPTS', 2),
    'search_max_parallel_stores' => (int) env('CHANNEL_SEARCH_MAX_PARALLEL_STORES', 8),

    'search_concurrency_driver' => env('CHANNEL_SEARCH_CONCURRENCY_DRIVER', 'process'),

    'media_mirror_timeout_seconds' => (int) env('CHANNEL_MEDIA_MIRROR_TIMEOUT_SECONDS', 15),
    'media_mirror_connect_timeout_seconds' => (int) env('CHANNEL_MEDIA_MIRROR_CONNECT_TIMEOUT_SECONDS', 5),
    'media_mirror_max_bytes' => (int) env('CHANNEL_MEDIA_MIRROR_MAX_BYTES', 10 * 1024 * 1024),

    'lazada_defaults' => [
        'primary_category' => env('LAZADA_DEFAULT_CATEGORY_ID'),
        'brand' => env('LAZADA_DEFAULT_BRAND', 'No Brand'),
    ],
];
