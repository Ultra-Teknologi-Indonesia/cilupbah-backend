<?php

return [

    'import_hours' => (int) env('IMPORT_FILE_RETENTION_HOURS', 24 * 7),

    'export_hours' => (int) env('EXPORT_FILE_RETENTION_HOURS', 24 * 7),
];
