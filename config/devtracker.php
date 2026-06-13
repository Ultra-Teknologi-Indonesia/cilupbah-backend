<?php

return [

    'allowed_envs' => explode(',', (string) env('DEVTRACKER_ALLOWED_ENVS', 'local,staging')),

    'enabled' => (bool) env('DEVTRACKER_ENABLED', false),

    'basic_auth' => [
        'user' => env('DEVTRACKER_USER'),
        'pass' => env('DEVTRACKER_PASS'),
    ],
];
