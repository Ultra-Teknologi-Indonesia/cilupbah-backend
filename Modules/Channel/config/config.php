<?php

return [
    'name' => 'Channel',
    'api_rate_limit_per_second' => (int) env('CHANNEL_API_RATE_LIMIT_PER_SECOND', 8),

    'auto_push_product_content' => (bool) env('CHANNEL_AUTO_PUSH_PRODUCT_CONTENT', false),

    'lazada_video_enabled' => (bool) env('LAZADA_VIDEO_ENABLED', true),

    'shopee_max_models' => (int) env('SHOPEE_MAX_MODELS', 50),

    'download_retry_attempts' => (int) env('CHANNEL_DOWNLOAD_RETRY_ATTEMPTS', 4),
    'download_max_pages' => (int) env('CHANNEL_DOWNLOAD_MAX_PAGES', 10000),

    'search_cache_ttl_seconds' => (int) env('CHANNEL_SEARCH_CACHE_TTL_SECONDS', 30),
    'search_remote_timeout_seconds' => (int) env('CHANNEL_SEARCH_REMOTE_TIMEOUT_SECONDS', 8),
    'search_max_parallel_stores' => (int) env('CHANNEL_SEARCH_MAX_PARALLEL_STORES', 8),

    'search_concurrency_driver' => env('CHANNEL_SEARCH_CONCURRENCY_DRIVER', 'process'),

    'lazada_defaults' => [
        'primary_category' => env('LAZADA_DEFAULT_CATEGORY_ID'),
        'brand' => env('LAZADA_DEFAULT_BRAND', 'No Brand'),
    ],
];
