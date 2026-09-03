<?php

return [
    'connection' => env('EXPORT_QUEUE_CONNECTION', 'redis-long'),
    'queue' => env('QUEUE_NAME_EXPORTS', 'exports'),
    'memory_limit' => env('EXPORT_MEMORY_LIMIT', '1536M'),
    'pdf_chunk_size' => max(50, (int) env('EXPORT_PDF_CHUNK_SIZE', 250)),
    'timeout' => max(120, (int) env('EXPORT_TIMEOUT', 900)),
    'csv_memory_limit' => env('EXPORT_CSV_MEMORY_LIMIT', '512M'),
    'csv_chunk_size' => max(100, (int) env('EXPORT_CSV_CHUNK_SIZE', 250)),
    'catalog_connection' => env('CATALOG_EXPORT_QUEUE_CONNECTION', 'redis-long'),
    'catalog_queue' => env('QUEUE_NAME_CATALOG_EXPORTS', 'catalog-exports'),
    'catalog_memory_limit' => env('CATALOG_EXPORT_MEMORY_LIMIT', '512M'),
    'catalog_timeout' => max(120, (int) env('CATALOG_EXPORT_TIMEOUT', 600)),
    'catalog_chunk_size' => max(100, (int) env('CATALOG_EXPORT_CHUNK_SIZE', 250)),
    'dedicated_queues' => [
        env('QUEUE_NAME_EXPORTS', 'exports'),
        env('QUEUE_NAME_CATALOG_EXPORTS', 'catalog-exports'),
    ],
];
