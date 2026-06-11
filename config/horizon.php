<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            // 'webhooks' = outbound webhook internal (Modules/Webhook) — sebelumnya tidak
            // dilayani supervisor mana pun sehingga job webhook tidak pernah terkirim.
            'queue' => ['default', env('QUEUE_NAME_TIKTOK_WEBHOOKS', 'tiktok-webhooks'), env('QUEUE_NAME_CHANNEL_SYNC', 'channel-sync'), env('QUEUE_NAME_PRODUCT', 'product'), env('WEBHOOK_QUEUE', 'webhooks')],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-orders' => [
            'connection' => 'redis',
            'queue' => ['orders'],
            'balance' => 'auto',
            'minProcesses' => 2,
            'maxProcesses' => 10,
            'timeout' => 60,
            'tries' => 3,
            'backoff' => [5, 15, 30],
            'memory' => 128,
            'nice' => 0,
        ],
        'supervisor-fulfillment' => [
            'connection' => 'redis',
            'queue' => ['fulfillment'],
            'balance' => 'auto',
            'minProcesses' => 2,
            'maxProcesses' => 10,
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
            'timeout' => 900,
            'tries' => 3,
            'backoff' => [10, 30, 60],
            'memory' => 256,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-default' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-orders' => [
                'minProcesses' => 2,
                'maxProcesses' => 10,
            ],
            'supervisor-fulfillment' => [
                'minProcesses' => 2,
                'maxProcesses' => 10,
            ],
            'supervisor-stock-sync' => [
                'minProcesses' => 2,
                'maxProcesses' => 10,
            ],
            'supervisor-failed-jobs' => [
                'minProcesses' => 1,
                'maxProcesses' => 3,
            ],
            'supervisor-stock' => [
                'minProcesses' => 2,
                'maxProcesses' => 5,
            ],
            'supervisor-downloads' => [
                'minProcesses' => 1,
                'maxProcesses' => 5,
            ],
        ],

        // Staging = box 4GB RAM (baseline ~75% terpakai). Total worker dibatasi ~9 (dari 38)
        // agar Horizon tidak scale-up sampai RAM habis → swap thrashing → load meledak.
        // ~9 worker x ~60MB ≈ 540MB, muat di headroom ~1GB. Setiap worker bootstrap Laravel penuh.
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
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

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
