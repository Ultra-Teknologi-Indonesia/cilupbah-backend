<?php

declare(strict_types=1);

return [
    'stock_cutover_console' => [

        'token' => env('STOCK_CUTOVER_CONSOLE_TOKEN'),
        'queue_connection' => env('STOCK_CUTOVER_CONSOLE_QUEUE_CONNECTION', 'redis-long'),
        'queue' => env('STOCK_CUTOVER_CONSOLE_QUEUE', 'stock-cutover'),

        'upload_disk' => env('STOCK_CUTOVER_CONSOLE_DISK', 's3'),
        'report_disk' => env('STOCK_CUTOVER_CONSOLE_REPORT_DISK', env('STOCK_CUTOVER_CONSOLE_DISK', 's3')),
        'max_upload_kilobytes' => (int) env('STOCK_CUTOVER_CONSOLE_MAX_UPLOAD_KB', 10240),
    ],
];
