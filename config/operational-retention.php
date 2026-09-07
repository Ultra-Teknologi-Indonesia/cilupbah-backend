<?php

return [
    /*
     * Pembersihan dilakukan secara bertahap oleh CronJob terpisah. Nilai per
     * proses dibatasi agar tidak mengambil lock panjang pada tabel operasional.
     */
    'batch_size' => max(100, (int) env('OPERATIONAL_RETENTION_BATCH_SIZE', 500)),
    'max_rows_per_resource' => max(100, (int) env('OPERATIONAL_RETENTION_MAX_ROWS_PER_RESOURCE', 5000)),

    'failed_jobs_hours' => max(1, (int) env('FAILED_JOBS_RETENTION_HOURS', 24 * 14)),
    'notifications_hours' => max(1, (int) env('NOTIFICATION_RETENTION_HOURS', 24 * 90)),
    'webhook_completed_hours' => max(1, (int) env('WEBHOOK_COMPLETED_RETENTION_HOURS', 24 * 30)),
];
