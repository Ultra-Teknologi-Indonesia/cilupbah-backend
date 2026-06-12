<?php

return [
    'name' => 'Webhook',

    // Nama queue khusus webhook (terpisah dari channel_sync/downloads).
    'queue' => env('WEBHOOK_QUEUE', 'webhooks'),

    // Timeout HTTP pengiriman webhook (detik).
    'timeout' => env('WEBHOOK_TIMEOUT', 10),
];
