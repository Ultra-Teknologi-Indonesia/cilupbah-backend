<?php

return [

    'local_first' => (bool) env('LABEL_LOCAL_FIRST', true),
    'spool_disk' => env('LABEL_PRINT_SPOOL_DISK', 'print_spool'),
    'archive_disk' => env('LABEL_PRINT_ARCHIVE_DISK', 'documents'),
    'archive_after_print_seconds' => max(5, (int) env('LABEL_ARCHIVE_AFTER_PRINT_SECONDS', 30)),

    'marketplace_wait_recovery_minutes' => max(
        1,
        (int) env('LABEL_MARKETPLACE_WAIT_RECOVERY_MINUTES', 5),
    ),

    'awb_request_dedupe_seconds' => (int) env('LABEL_AWB_REQUEST_DEDUPE_SECONDS', 300),

    'finalize_lock_seconds' => max(120, (int) env('LABEL_FINALIZE_LOCK_SECONDS', 900)),
    'finalize_retry_delay_seconds' => max(1, (int) env('LABEL_FINALIZE_RETRY_DELAY_SECONDS', 15)),
    'awb_verification_delay_seconds' => max(15, (int) env('LABEL_AWB_VERIFICATION_DELAY_SECONDS', 60)),
];
