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

    public function test_multi_queue_supervisors_use_a_bounded_shared_worker_pool(): void
    {
        foreach ([
            'supervisor-default' => [1, 1],
            'supervisor-order-operations' => [2, 2],
            'supervisor-channel-sync' => [2, 2],
            'supervisor-product-validation' => [1, 1],
            'supervisor-channel-operations' => [1, 1],
            'supervisor-stock-default' => [1, 1],
            'supervisor-tiktok-packages' => [1, 2],
            'supervisor-tiktok-webhooks-background' => [1, 1],
            'supervisor-shopee-webhooks-background' => [1, 1],
            'supervisor-lazada-fulfillment' => [1, 1],
            'supervisor-lazada-webhooks-background' => [1, 1],
            'supervisor-labels' => [4, 4],
            'supervisor-label-awb' => [4, 4],
            'supervisor-label-archive' => [1, 1],
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
            'supervisor-shopee-orders' => 3,
            'supervisor-tiktok-orders' => 4,
            'supervisor-lazada-orders' => 2,
            'supervisor-shopee-webhooks-operational' => 2,
            'supervisor-tiktok-webhooks-operational' => 3,
            'supervisor-lazada-webhooks-operational' => 2,
        ] as $name => $maxProcesses) {
            $supervisor = config("horizon.defaults.{$name}");

            $this->assertSame('auto', $supervisor['balance'] ?? null, "{$name} harus autoscale.");
            $this->assertSame('size', $supervisor['autoScalingStrategy'] ?? null, "{$name} harus scale berdasarkan ukuran antrean.");
            $this->assertSame(1, $supervisor['minProcesses'] ?? null, "{$name} minimum worker tidak sesuai.");
            $this->assertSame($maxProcesses, $supervisor['maxProcesses'] ?? null, "{$name} maksimum worker harus dibatasi.");
            $this->assertSame(1, $supervisor['balanceMaxShift'] ?? null, "{$name} scale step terlalu besar.");
            $this->assertSame(5, $supervisor['balanceCooldown'] ?? null, "{$name} cooldown autoscale tidak sesuai.");
        }

        $stock = config('horizon.defaults.supervisor-stock');
        $this->assertSame('auto', $stock['balance'] ?? null);
        $this->assertSame('size', $stock['autoScalingStrategy'] ?? null);
        $this->assertSame(1, $stock['minProcesses'] ?? null);
        $this->assertSame(3, $stock['maxProcesses'] ?? null);
        $this->assertSame(1, $stock['balanceMaxShift'] ?? null);
        $this->assertSame(5, $stock['balanceCooldown'] ?? null);
    }

    public function test_channel_orders_and_operational_webhooks_are_isolated_from_background_work(): void
    {
        $expectedQueues = [
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

            $this->assertLessThanOrEqual(
                4608,
                $withMasterMegabytes,
                "Profil Horizon {$profile} melewati ceiling 4.5GiB worker+master."
            );
        }

        $critical = config('horizon.profiles.critical', []);
        $background = config('horizon.profiles.background', []);
        $labels = config('horizon.profiles.labels', []);
        $this->assertSame([], array_intersect($critical, $background));
        $this->assertSame([], array_intersect($labels, array_merge($critical, $background)));
        $expectedSupervisors = array_values(array_unique(array_keys($allSupervisors)));
        $profileSupervisors = array_values(array_unique(array_merge($critical, $background, $labels)));
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

        $criticalQueues = $queuesByProfile['critical'] ?? [];
        $backgroundQueues = $queuesByProfile['background'] ?? [];

        $this->assertSame(
            [],
            array_values(array_intersect($criticalQueues, $backgroundQueues)),
            'Queue critical dan background tidak boleh overlap karena bisa memproses side effect bersamaan.'
        );

        $expected = $this->servedQueues();

        $profileQueues = array_values(array_unique(array_merge(
            $criticalQueues,
            $backgroundQueues,
            $queuesByProfile['labels'] ?? [],
            (array) config('queue.dedicated_queues', []),
        )));
        sort($expected);
        sort($profileQueues);

        $this->assertSame($expected, $profileQueues);
    }
}
