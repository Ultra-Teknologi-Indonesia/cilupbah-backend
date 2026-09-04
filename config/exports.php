<?php

return [
    'connection' => env('EXPORT_QUEUE_CONNECTION', 'redis-long'),
    'queue' => env('QUEUE_NAME_EXPORTS_SHEET', env('QUEUE_NAME_EXPORTS', 'exports-sheet')),
    'memory_limit' => env('EXPORT_SHEET_MEMORY_LIMIT', '768M'),
    'pdf_chunk_size' => max(50, (int) env('EXPORT_PDF_CHUNK_SIZE', 250)),
    'timeout' => max(120, (int) env('EXPORT_TIMEOUT', 900)),
    'csv_memory_limit' => env('EXPORT_CSV_MEMORY_LIMIT', '512M'),
    'csv_chunk_size' => max(100, (int) env('EXPORT_CSV_CHUNK_SIZE', 250)),
    'catalog_connection' => env('CATALOG_EXPORT_QUEUE_CONNECTION', 'redis-long'),
    'catalog_queue' => env('QUEUE_NAME_CATALOG_EXPORTS', 'catalog-exports'),
    'catalog_memory_limit' => env('CATALOG_EXPORT_MEMORY_LIMIT', '512M'),
    'catalog_timeout' => max(120, (int) env('CATALOG_EXPORT_TIMEOUT', 600)),
    'catalog_chunk_size' => max(100, (int) env('CATALOG_EXPORT_CHUNK_SIZE', 250)),
    'pdf_connection' => env('EXPORT_PDF_QUEUE_CONNECTION'),
    'pdf_queue' => env('QUEUE_NAME_EXPORTS_PDF', 'exports-pdf'),
    'pdf_memory_limit' => env('EXPORT_PDF_MEMORY_LIMIT', '1536M'),
    'pdf_timeout' => max(120, (int) env('EXPORT_PDF_TIMEOUT', 900)),
    'sheet_connection' => env('EXPORT_SHEET_QUEUE_CONNECTION'),
    'sheet_queue' => env('QUEUE_NAME_EXPORTS_SHEET', env('QUEUE_NAME_EXPORTS', 'exports-sheet')),
    'sheet_memory_limit' => env('EXPORT_SHEET_MEMORY_LIMIT', '768M'),
    'sheet_timeout' => max(120, (int) env('EXPORT_SHEET_TIMEOUT', 720)),
    'dedicated_queues' => [
        env('QUEUE_NAME_EXPORTS_PDF', 'exports-pdf'),
        env('QUEUE_NAME_EXPORTS_SHEET', env('QUEUE_NAME_EXPORTS', 'exports-sheet')),
        env('QUEUE_NAME_CATALOG_EXPORTS', 'catalog-exports'),
    ],
];
