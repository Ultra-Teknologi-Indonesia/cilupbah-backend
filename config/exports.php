<?php

return [
    'connection' => env('EXPORT_QUEUE_CONNECTION', 'redis-long'),
    'queue' => env('QUEUE_NAME_EXPORTS', 'exports'),
    'memory_limit' => env('EXPORT_MEMORY_LIMIT', '1536M'),
    'pdf_chunk_size' => max(50, (int) env('EXPORT_PDF_CHUNK_SIZE', 250)),
    'timeout' => max(120, (int) env('EXPORT_TIMEOUT', 900)),
    'dedicated_queues' => [
        env('QUEUE_NAME_EXPORTS', 'exports'),
    ],
];
