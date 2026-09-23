<?php

declare(strict_types=1);

return [
    'gotenberg' => [
        'url' => env('GOTENBERG_URL'),
        'connect_timeout_seconds' => max(1, (int) env('GOTENBERG_CONNECT_TIMEOUT_SECONDS', 5)),
        'timeout_seconds' => max(5, (int) env('GOTENBERG_TIMEOUT_SECONDS', 60)),
    ],
];
