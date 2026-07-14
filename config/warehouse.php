<?php

return [
    'system_migration_user_id' => env('WAREHOUSE_SYSTEM_USER_ID'),

    'min_mobile_version' => env('MIN_MOBILE_VERSION', '2.0.0'),

    'mobile_version_gate_hard' => env('MOBILE_VERSION_GATE_HARD', false),

    'mobile_upgrade_url' => env('MOBILE_UPGRADE_URL', 'https://cilupbah.com/mobile/upgrade'),

    'idempotency_ttl_hours' => env('IDEMPOTENCY_TTL_HOURS', 24),

    'unassign_roles' => [
        'primary'  => ['owner', 'admin', 'kepala gudang'],
        'inbound'  => ['leader inbound'],
        'putaway'  => ['leader inbound'],
        'picking'  => ['leader outbound'],
        'reset_destructive' => ['owner', 'admin'],
        'reset_picking'     => ['owner', 'admin', 'kepala gudang'],
    ],
];
