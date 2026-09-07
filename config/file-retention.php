<?php

return [
    /*
     * The original import workbook is only needed while an import is being
     * processed. Failed or abandoned uploads remain available briefly for
     * investigation, then are removed from either local storage or R2.
     */
    'import_hours' => (int) env('IMPORT_FILE_RETENTION_HOURS', 24 * 7),

    /*
     * Generated exports may be downloaded again during this window. Their
     * database history remains after the artifact itself is purged.
     */
    'export_hours' => (int) env('EXPORT_FILE_RETENTION_HOURS', 24 * 7),
];
