<?php

return [
    'sync_auto_pause_time' => env(
        'CHANNEL_SYNC_AUTO_PAUSE_TIME',
        env('CHANNEL_SYNC_AUTO_PAUSE_AT', '12:00'),
    ),
    'sync_timezone' => env('CHANNEL_SYNC_TIMEZONE', 'Asia/Jakarta'),
];
