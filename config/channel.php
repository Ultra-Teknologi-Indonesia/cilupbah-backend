<?php

return [
    'sync_auto_pause_time' => env(
        'CHANNEL_SYNC_AUTO_PAUSE_TIME',
        env('CHANNEL_SYNC_AUTO_PAUSE_AT', '12:00'),
    ),
    'sync_timezone' => env('CHANNEL_SYNC_TIMEZONE', 'Asia/Jakarta'),
    'order_refresh_batch_size' => max(1, min(50, (int) env('CHANNEL_ORDER_REFRESH_BATCH_SIZE', 50))),
    'order_refresh_batch_window_ms' => max(0, min(2000, (int) env('CHANNEL_ORDER_REFRESH_BATCH_WINDOW_MS', 150))),
];
