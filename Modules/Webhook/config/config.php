<?php

return [
    'name' => 'Webhook',

    // Nama queue khusus webhook (terpisah dari channel_sync/downloads).
    'queue' => env('WEBHOOK_QUEUE', 'webhooks'),

    // Timeout HTTP pengiriman webhook (detik).
    'timeout' => env('WEBHOOK_TIMEOUT', 10),

    // Skema URL target yang diizinkan (anti-SSRF). Host privat/loopback/reserved selalu ditolak.
    'allowed_schemes' => array_filter(explode(',', env('WEBHOOK_ALLOWED_SCHEMES', 'https'))),

    // Nonaktifkan subscription otomatis setelah sekian kegagalan beruntun (dead endpoint).
    'max_consecutive_failures' => (int) env('WEBHOOK_MAX_CONSECUTIVE_FAILURES', 15),

    // TTL cache daftar event aktif (detik) — dihangatkan ulang saat subscription berubah.
    'active_events_ttl' => (int) env('WEBHOOK_ACTIVE_EVENTS_TTL', 300),
];
