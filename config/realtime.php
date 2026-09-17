<?php

return [
    'enabled' => (bool) env('REALTIME_SSE_ENABLED', true),
    'redis_connection' => env('REALTIME_REDIS_CONNECTION', 'default'),
    'stream_prefix' => env('REALTIME_STREAM_PREFIX', 'realtime:user'),
    'stream_max_length' => (int) env('REALTIME_STREAM_MAX_LENGTH', 1000),
    'stream_max_seconds' => (int) env('REALTIME_STREAM_MAX_SECONDS', 20),
    'heartbeat_seconds' => (int) env('REALTIME_HEARTBEAT_SECONDS', 8),
    'read_block_milliseconds' => (int) env('REALTIME_READ_BLOCK_MS', 4000),
    'max_events_per_read' => (int) env('REALTIME_MAX_EVENTS_PER_READ', 50),

    'max_active_connections' => (int) env('REALTIME_MAX_ACTIVE_CONNECTIONS', 3),
    'active_lease_ttl_seconds' => (int) env('REALTIME_ACTIVE_LEASE_TTL_SECONDS', 30),
    'active_connections_key' => env('REALTIME_ACTIVE_CONNECTIONS_KEY', 'realtime:sse:active'),
];
