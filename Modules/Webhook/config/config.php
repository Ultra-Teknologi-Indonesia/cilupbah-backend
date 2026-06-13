<?php

return [
    'name' => 'Webhook',

    'queue' => env('WEBHOOK_QUEUE', 'webhooks'),

    'timeout' => env('WEBHOOK_TIMEOUT', 10),

    'allowed_schemes' => array_filter(explode(',', env('WEBHOOK_ALLOWED_SCHEMES', 'https'))),

    'max_consecutive_failures' => (int) env('WEBHOOK_MAX_CONSECUTIVE_FAILURES', 15),

    'active_events_ttl' => (int) env('WEBHOOK_ACTIVE_EVENTS_TTL', 300),
];
