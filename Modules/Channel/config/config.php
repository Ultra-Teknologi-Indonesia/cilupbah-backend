<?php

return [
    'name' => 'Channel',
    'api_rate_limit_per_second' => (int) env('CHANNEL_API_RATE_LIMIT_PER_SECOND', 8),

    /*
     * When true, editing a master product internally auto-pushes the full content
     * (name, variations, media) to every connected marketplace via ProductObserver.
     * Disabled by default: staff edit channel-specific content directly on each
     * marketplace (different per-channel rules, e.g. TikTok's 25-char name minimum)
     * and then download it back, so auto-push would overwrite that data. Manual push
     * endpoints (products/push, bulk-push) and stock sync are unaffected.
     */
    'auto_push_product_content' => (bool) env('CHANNEL_AUTO_PUSH_PRODUCT_CONTENT', false),

    'lazada_defaults' => [
        'primary_category' => env('LAZADA_DEFAULT_CATEGORY_ID'),
        'brand' => env('LAZADA_DEFAULT_BRAND', 'No Brand'),
    ],
];
