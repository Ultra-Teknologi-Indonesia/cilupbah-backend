<?php

use Illuminate\Support\Str;

return [

    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web', \App\Http\Middleware\HorizonBasicAuth::class],

    'allowed_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('HORIZON_ALLOWED_EMAILS', ''))
    ))),

    'waits' => [
        'redis:default' => 60,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [

    ],

    'silenced_tags' => [

    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'notifications' => [
        'slack_webhook' => env('HORIZON_SLACK_WEBHOOK'),
        'slack_channel' => env('HORIZON_SLACK_CHANNEL'),
        'mail' => env('HORIZON_MAIL_TO'),
    ],

    'fast_termination' => false,

    'memory_limit' => 256,

    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default', 'notifications', env('WEBHOOK_QUEUE', 'webhooks')],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 500,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-orders' => [
            'connection' => 'redis',
            'queue' => ['orders', env('QUEUE_NAME_WEBHOOK_DOWNLOADS', 'webhook-downloads'), env('QUEUE_NAME_TIKTOK_WEBHOOKS', 'tiktok-webhooks')],
            'balance' => 'auto',
            'minProcesses' => 4,
            'maxProcesses' => 16,
            'maxJobs' => 500,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [5, 15, 30],
            'memory' => 256,
            'nice' => 0,
        ],
        'supervisor-fulfillment' => [
            'connection' => 'redis',
            'queue' => ['fulfillment'],
            'balance' => 'auto',
            'minProcesses' => 2,
            'maxProcesses' => 10,
            'maxJobs' => 500,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [5, 15, 30],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-stock-sync' => [
            'connection' => 'redis',
            'queue' => ['stock-sync'],
            'balance' => 'auto',
            'minProcesses' => 2,
            'maxProcesses' => 10,
            'maxJobs' => 500,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [5, 15, 30],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-channel-sync' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_NAME_CHANNEL_SYNC', 'channel-sync'), env('QUEUE_NAME_PRODUCT', 'product')],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 5,
            'maxJobs' => 500,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [5, 15, 30],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-failed-jobs' => [
            'connection' => 'redis',
            'queue' => ['failed-jobs'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'maxJobs' => 500,
            'timeout' => 60,
            'tries' => 1,
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-stock' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_NAME_STOCK_CRITICAL', 'stock-critical'), env('QUEUE_NAME_STOCK_DEFAULT', 'stock-default')],
            'balance' => 'simple',
            'minProcesses' => 2,
            'maxProcesses' => 5,
            'maxJobs' => 500,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [3, 10, 30],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-downloads' => [
            'connection' => 'redis-long',
            'queue' => ['downloads'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'maxJobs' => 100,
            'timeout' => 900,
            'tries' => 3,
            'backoff' => [10, 30, 60],
            'memory' => 256,
            'nice' => 0,
        ],
        'supervisor-labels' => [
            'connection' => 'redis-long',
            'queue' => ['labels'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'maxJobs' => 100,
            'timeout' => 600,
            'tries' => 1,
            'memory' => 1024,
            'nice' => 0,
        ],
        'supervisor-tiktok-webhooks' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_NAME_TIKTOK_WEBHOOKS', 'tiktok-webhooks')],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 3,
            'maxProcesses' => 8,
            'maxJobs' => 500,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 256,
            'nice' => 0,
        ],
        'supervisor-shopee-webhooks' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_NAME_SHOPEE_WEBHOOKS', 'shopee-webhooks'), env('QUEUE_NAME_WEBHOOK_DOWNLOADS', 'webhook-downloads')],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 3,
            'maxProcesses' => 8,
            'maxJobs' => 500,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 256,
            'nice' => 0,
        ],
        'supervisor-lazada-webhooks' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_NAME_LAZADA_WEBHOOKS', 'lazada-webhooks')],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxJobs' => 500,
            'timeout' => 120,
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'memory' => 256,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-default' => [
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-orders' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-fulfillment' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-stock-sync' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-channel-sync' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-failed-jobs' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-stock' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-downloads' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-labels' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-tiktok-webhooks' => [
                'minProcesses' => 3,
                'maxProcesses' => 8,
                'balanceMaxShift' => 2,
                'balanceCooldown' => 1,
                'memory' => 256,
            ],
            'supervisor-shopee-webhooks' => [
                'minProcesses' => 3,
                'maxProcesses' => 8,
                'balanceMaxShift' => 2,
                'balanceCooldown' => 1,
                'memory' => 256,
            ],
            'supervisor-lazada-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 2,
                'memory' => 256,
            ],
        ],

        'staging' => [
            'supervisor-default' => [
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 5,
            ],
            'supervisor-orders' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-fulfillment' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-stock-sync' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-channel-sync' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-failed-jobs' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-stock' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-downloads' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-labels' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-tiktok-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 3,
            ],
            'supervisor-shopee-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 3,
            ],
            'supervisor-lazada-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
        ],

        'local' => [
            'supervisor-default' => [
                'maxProcesses' => 3,
            ],
            'supervisor-orders' => [
                'minProcesses' => 1,
                'maxProcesses' => 3,
            ],
            'supervisor-fulfillment' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-stock-sync' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-channel-sync' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-failed-jobs' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            'supervisor-stock' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-downloads' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-labels' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-tiktok-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-shopee-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-lazada-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
