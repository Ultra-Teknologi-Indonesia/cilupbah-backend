<?php

return [
    // Environment yang boleh mengakses dev tracker (local & staging).
    'allowed_envs' => explode(',', (string) env('DEVTRACKER_ALLOWED_ENVS', 'local,staging')),

    // Override paksa nyalakan di environment lain (default false).
    'enabled' => (bool) env('DEVTRACKER_ENABLED', false),

    // Proteksi Basic Auth opsional (untuk staging). Kosongkan utk menonaktifkan.
    'basic_auth' => [
        'user' => env('DEVTRACKER_USER'),
        'pass' => env('DEVTRACKER_PASS'),
    ],
];
