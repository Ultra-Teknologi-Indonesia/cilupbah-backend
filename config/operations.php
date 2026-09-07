<?php

declare(strict_types=1);

return [
    'stock_cutover_console' => [
        // Deliberately has no default: an unset token makes every route return 404.
        'token' => env('STOCK_CUTOVER_CONSOLE_TOKEN'),
        'queue_connection' => env('STOCK_CUTOVER_CONSOLE_QUEUE_CONNECTION', 'redis-long'),
        'queue' => env('STOCK_CUTOVER_CONSOLE_QUEUE', 'stock-cutover'),
        // Input and generated reports are persisted privately in object storage.
        // Workers materialize a source file locally only for the duration of parsing it.
        'upload_disk' => env('STOCK_CUTOVER_CONSOLE_DISK', 's3'),
        'report_disk' => env('STOCK_CUTOVER_CONSOLE_REPORT_DISK', env('STOCK_CUTOVER_CONSOLE_DISK', 's3')),
        'max_upload_kilobytes' => (int) env('STOCK_CUTOVER_CONSOLE_MAX_UPLOAD_KB', 10240),
    ],
];
