<?php

return [
    'name' => 'Channel',
    'api_rate_limit_per_second' => (int) env('CHANNEL_API_RATE_LIMIT_PER_SECOND', 8),

    'auto_push_product_content' => (bool) env('CHANNEL_AUTO_PUSH_PRODUCT_CONTENT', false),

    // Video Lazada: ON. Endpoint /media/video/block/{create,upload,commit} (sesuai SDK resmi).
    // Aman: non-blocking + jaring pengaman retry-tanpa-video di LazadaAdapter, jadi kegagalan
    // video tidak pernah menggagalkan upload produk. Set LAZADA_VIDEO_ENABLED=false untuk mematikan.
    'lazada_video_enabled' => (bool) env('LAZADA_VIDEO_ENABLED', true),

    'lazada_defaults' => [
        'primary_category' => env('LAZADA_DEFAULT_CATEGORY_ID'),
        'brand' => env('LAZADA_DEFAULT_BRAND', 'No Brand'),
    ],
];
