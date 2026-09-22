<?php

namespace Tests\Feature;

use Tests\TestCase;

class HorizonQueueCoverageTest extends TestCase
{
    private function servedQueues(): array
    {
        $served = [];
        foreach (config('horizon.defaults', []) as $supervisor) {
            $served = array_merge($served, (array) ($supervisor['queue'] ?? []));
        }

        $served = array_merge($served, (array) config('queue.dedicated_queues', []));

        return array_values(array_unique($served));
    }

    public function test_all_named_queues_are_served_by_horizon(): void
    {
        $served = $this->servedQueues();

        $used = array_values(config('queue.names', []));
        $used[] = 'default';
        $used[] = config('webhook.queue', 'webhooks');
        $used[] = config('operations.stock_cutover_console.queue', 'stock-cutover');
        $used[] = config('operations.order_cutover_console.queue', 'order-cutover');

        foreach (array_unique($used) as $queue) {
            $this->assertContains(
                $queue,
                $served,
                "Queue '{$queue}' tidak dilayani supervisor Horizon mana pun — job akan menumpuk tanpa diproses."
            );
        }
    }

    public function test_every_job_class_queue_is_served(): void
    {
        $served = $this->servedQueues();

        $jobFiles = array_merge(
            glob(base_path('Modules/*/app/Jobs/*.php')) ?: [],
            glob(base_path('app/Jobs/*.php')) ?: [],
        );

        $this->assertNotEmpty($jobFiles, 'Tidak ada file job ditemukan — cek path glob.');

        foreach ($jobFiles as $file) {
            $src = file_get_contents($file);

            if (! preg_match("/onQueue\((?:config\('([^']+)'(?:\s*,\s*'([^']+)')?\)|'([^']+)')\)/", $src, $m)) {
                continue;
            }

            $queue = $m[3] ?? null;
            if (! $queue) {
                $queue = config($m[1], $m[2] ?? null);
            }

            $this->assertNotNull($queue, "Queue untuk job {$file} tidak ter-resolve.");
            $this->assertContains(
                $queue,
                $served,
                basename($file)." memakai queue '{$queue}' yang tidak dilayani supervisor Horizon."
            );
        }
    }

    public function test_supervisor_connections_exist_and_use_redis(): void
    {
        $connections = config('queue.connections', []);

        foreach (config('horizon.defaults', []) as $name => $supervisor) {
            $connection = $supervisor['connection'] ?? 'redis';

            $this->assertArrayHasKey($connection, $connections, "Supervisor {$name}: connection '{$connection}' tidak ada di config/queue.php.");
            $this->assertEquals('redis', $connections[$connection]['driver'] ?? null, "Supervisor {$name}: Horizon hanya bisa memproses driver redis.");
        }
    }

    public function test_cutover_queues_share_a_single_low_concurrency_supervisor(): void
    {
        $supervisor = config('horizon.defaults.supervisor-cutover');

        $this->assertIsArray($supervisor);
        $this->assertSame(
            config('operations.stock_cutover_console.queue_connection', 'redis-long'),
            $supervisor['connection'] ?? null,
        );
        $this->assertContains(
            config('operations.stock_cutover_console.queue', 'stock-cutover'),
            (array) ($supervisor['queue'] ?? []),
        );
        $this->assertContains(
            config('operations.order_cutover_console.queue', 'order-cutover'),
            (array) ($supervisor['queue'] ?? []),
        );
        $this->assertSame('off', $supervisor['balance'] ?? null);
        $this->assertSame(1, $supervisor['minProcesses'] ?? null);
        $this->assertSame(1, $supervisor['maxProcesses'] ?? null);
    }

    public function test_supervisors_have_bounded_baselines_and_elastic_operational_capacity(): void
    {
        foreach ([
            'supervisor-default' => [1, 1],
            'supervisor-stock-sync' => [1, 1],
            'supervisor-channel-sync' => [4, 4],
            'supervisor-channel-order-recovery' => [4, 4],
            'supervisor-channel-stock-critical' => [1, 1],
            'supervisor-channel-stock-normal' => [1, 1],
            'supervisor-channel-stock-outbox' => [1, 1],
            'supervisor-channel-order-refresh' => [1, 1],
            'supervisor-product-validation' => [1, 1],
            'supervisor-stock-default' => [1, 1],
            'supervisor-tiktok-packages' => [1, 2],
            'supervisor-tiktok-webhooks-background' => [1, 1],
            'supervisor-shopee-webhooks-background' => [1, 1],
            'supervisor-lazada-fulfillment' => [1, 1],
            'supervisor-lazada-webhooks-background' => [1, 1],
            'supervisor-labels' => [4, 4],
            'supervisor-label-prefetch' => [1, 1],
            'supervisor-label-awb' => [1, 1],
            'supervisor-label-archive' => [2, 2],
        ] as $name => [$minProcesses, $maxProcesses]) {
            $supervisor = config("horizon.defaults.{$name}");

            $this->assertSame('off', $supervisor['balance'] ?? null, "{$name} harus memakai pool bersama.");
            $this->assertSame($minProcesses, $supervisor['minProcesses'] ?? null, "{$name} min worker tidak sesuai.");
            $this->assertSame($maxProcesses, $supervisor['maxProcesses'] ?? null, "{$name} max worker tidak sesuai.");
        }

        $tracking = config('horizon.defaults.supervisor-shopee-tracking');
        $this->assertSame('auto', $tracking['balance'] ?? null);
        $this->assertSame('size', $tracking['autoScalingStrategy'] ?? null);
        $this->assertSame(2, $tracking['minProcesses'] ?? null);
        $this->assertSame(3, $tracking['maxProcesses'] ?? null);
        $this->assertSame(1, $tracking['balanceMaxShift'] ?? null);
        $this->assertSame(3, $tracking['balanceCooldown'] ?? null);

        foreach ([
            'supervisor-orders' => [1, 3],
            'supervisor-fulfillment' => [1, 2],
            'supervisor-shopee-orders' => [1, 4],
            'supervisor-tiktok-orders' => [1, 4],
            'supervisor-lazada-orders' => [1, 2],
            'supervisor-shopee-webhooks-operational' => [2, 2],
            'supervisor-tiktok-webhooks-operational' => [2, 3],
            'supervisor-lazada-webhooks-operational' => [1, 2],
        ] as $name => [$minProcesses, $maxProcesses]) {
            $supervisor = config("horizon.defaults.{$name}");

            $this->assertSame('auto', $supervisor['balance'] ?? null, "{$name} harus autoscale.");
            $this->assertSame('size', $supervisor['autoScalingStrategy'] ?? null, "{$name} harus scale berdasarkan ukuran antrean.");
            $this->assertSame($minProcesses, $supervisor['minProcesses'] ?? null, "{$name} minimum worker tidak sesuai.");
            $this->assertSame($maxProcesses, $supervisor['maxProcesses'] ?? null, "{$name} maksimum worker harus dibatasi.");
            $this->assertSame(1, $supervisor['balanceMaxShift'] ?? null, "{$name} scale step terlalu besar.");
            $this->assertSame(5, $supervisor['balanceCooldown'] ?? null, "{$name} cooldown autoscale tidak sesuai.");
        }

        $stock = config('horizon.defaults.supervisor-stock');
        $this->assertSame('auto', $stock['balance'] ?? null);
        $this->assertSame('size', $stock['autoScalingStrategy'] ?? null);
        $this->assertSame(1, $stock['minProcesses'] ?? null);
        $this->assertSame(4, $stock['maxProcesses'] ?? null);
        $this->assertSame(1, $stock['balanceMaxShift'] ?? null);
        $this->assertSame(5, $stock['balanceCooldown'] ?? null);

        foreach (['shopee', 'tiktok', 'lazada'] as $channel) {
            $request = config("horizon.defaults.supervisor-label-awb-request-{$channel}");
            $this->assertSame('auto', $request['balance'] ?? null);
            $this->assertSame(1, $request['minProcesses'] ?? null);
            $this->assertSame(2, $request['maxProcesses'] ?? null);

            $download = config("horizon.defaults.supervisor-label-download-{$channel}");
            $this->assertSame('auto', $download['balance'] ?? null);
            $this->assertSame(1, $download['minProcesses'] ?? null);
            $this->assertSame(2, $download['maxProcesses'] ?? null);
        }
    }

    public function test_channel_orders_and_operational_webhooks_are_isolated_from_background_work(): void
    {
        $expectedQueues = [
            'supervisor-channel-order-refresh' => [config('queue.names.channel_order_refresh', 'channel-order-refresh')],
            'supervisor-shopee-orders' => [config('queue.names.shopee_orders', 'shopee-orders')],
            'supervisor-tiktok-orders' => [config('queue.names.tiktok_orders', 'tiktok-orders')],
            'supervisor-lazada-orders' => [config('queue.names.lazada_orders', 'lazada-orders')],
            'supervisor-tiktok-packages' => [
                env('QUEUE_NAME_TIKTOK_PACKAGES', 'tiktok-packages'),
            ],
            'supervisor-tiktok-webhooks-operational' => [
                env('QUEUE_NAME_TIKTOK_WEBHOOKS', 'tiktok-webhooks'),
            ],
            'supervisor-tiktok-webhooks-background' => [
                env('QUEUE_NAME_TIKTOK_AFTERSALES', 'tiktok-aftersales'),
                env('QUEUE_NAME_TIKTOK_CATALOG', 'tiktok-catalog'),
            ],
            'supervisor-shopee-webhooks-operational' => [
                env('QUEUE_NAME_SHOPEE_WEBHOOKS', 'shopee-webhooks'),
            ],
            'supervisor-shopee-tracking' => [
                env('QUEUE_NAME_SHOPEE_TRACKING', 'shopee-tracking'),
            ],
            'supervisor-shopee-webhooks-background' => [
                env('QUEUE_NAME_SHOPEE_AFTERSALES', 'shopee-aftersales'),
                env('QUEUE_NAME_SHOPEE_CATALOG', 'shopee-catalog'),
                env('QUEUE_NAME_WEBHOOK_DOWNLOADS', 'webhook-downloads'),
            ],
            'supervisor-lazada-webhooks-operational' => [
                env('QUEUE_NAME_LAZADA_WEBHOOKS', 'lazada-webhooks'),
            ],
            'supervisor-lazada-fulfillment' => [
                env('QUEUE_NAME_LAZADA_FULFILLMENT', 'lazada-fulfillment'),
            ],
            'supervisor-lazada-webhooks-background' => [
                env('QUEUE_NAME_LAZADA_AFTERSALES', 'lazada-aftersales'),
                env('QUEUE_NAME_LAZADA_CATALOG', 'lazada-catalog'),
            ],
        ];

        foreach ($expectedQueues as $supervisor => $queues) {
            $this->assertSame($queues, config("horizon.defaults.{$supervisor}.queue"));
        }
    }

    public function test_production_worker_memory_ceiling_leaves_pod_headroom(): void
    {
        $allSupervisors = config('horizon.defaults', []);

        foreach (config('horizon.profiles', []) as $profile => $names) {
            $workerMegabytes = collect($names)->sum(
                fn (string $name): int => (int) ($allSupervisors[$name]['maxProcesses'] ?? 0)
                    * (int) ($allSupervisors[$name]['memory'] ?? 0),
            );
            $withMasterMegabytes = $workerMegabytes + (int) config('horizon.memory_limit');

            $ceiling = [
                'order-intake' => 3072,
                'fulfillment' => 2048,
                'stock' => 3072,
                'marketplace-ops' => 3072,
                'background' => 3072,
                'labels-pdf' => 5120,
                'labels-prefetch' => 1024,
                'labels-awb' => 2560,
                'labels-archive' => 512,
                'maintenance' => 2048,
                'order-recovery' => 2048,
            ][$profile] ?? 2048;

            $this->assertLessThanOrEqual(
                $ceiling,
                $withMasterMegabytes,
                "Profil Horizon {$profile} melewati ceiling resource pod yang ditentukan."
            );
        }

        $profiles = config('horizon.profiles', []);
        $profileNames = array_keys($profiles);
        foreach ($profileNames as $index => $profile) {
            foreach (array_slice($profileNames, $index + 1) as $otherProfile) {
                $this->assertSame(
                    [],
                    array_intersect($profiles[$profile], $profiles[$otherProfile]),
                    "Supervisor {$profile} overlap dengan {$otherProfile}.",
                );
            }
        }
        $expectedSupervisors = array_values(array_unique(array_keys($allSupervisors)));
        $profileSupervisors = array_values(array_unique(array_merge(...array_values($profiles))));
        sort($expectedSupervisors);
        sort($profileSupervisors);
        $this->assertSame(
            $expectedSupervisors,
            $profileSupervisors,
            'Setiap supervisor harus berada tepat di satu profil production.'
        );
    }

    public function test_production_profiles_cover_queues_without_overlap(): void
    {
        $allSupervisors = config('horizon.defaults', []);
        $queuesByProfile = [];

        foreach (config('horizon.profiles', []) as $profile => $names) {
            $queuesByProfile[$profile] = array_values(array_unique(array_merge(
                ...array_map(
                    fn (string $name): array => (array) ($allSupervisors[$name]['queue'] ?? []),
                    $names,
                ),
            )));
        }

        $profileNames = array_keys($queuesByProfile);
        foreach ($profileNames as $index => $profile) {
            foreach (array_slice($profileNames, $index + 1) as $otherProfile) {
                $this->assertSame(
                    [],
                    array_values(array_intersect($queuesByProfile[$profile], $queuesByProfile[$otherProfile])),
                    "Queue {$profile} dan {$otherProfile} tidak boleh overlap karena bisa memproses side effect bersamaan.",
                );
            }
        }

        $expected = $this->servedQueues();

        $profileQueues = [];
        foreach ($queuesByProfile as $queues) {
            $profileQueues = array_merge($profileQueues, $queues);
        }
        $profileQueues = array_values(array_unique(array_merge(
            $profileQueues,
            (array) config('queue.dedicated_queues', []),
        )));
        sort($expected);
        sort($profileQueues);

        $this->assertSame($expected, $profileQueues);
    }
}
