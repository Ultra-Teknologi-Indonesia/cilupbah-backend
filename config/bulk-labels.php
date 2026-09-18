<?php

return [

    'local_first' => (bool) env('LABEL_LOCAL_FIRST', false),
    'spool_disk' => env('LABEL_PRINT_SPOOL_DISK', 'print_spool'),
    'archive_disk' => env('LABEL_PRINT_ARCHIVE_DISK', 'documents'),

    'awb_request_dedupe_seconds' => (int) env('LABEL_AWB_REQUEST_DEDUPE_SECONDS', 300),
];
