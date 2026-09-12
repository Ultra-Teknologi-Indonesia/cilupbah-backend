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
            'supervisor-default' => 1,
            'supervisor-order-operations' => 2,
            'supervisor-channel-sync' => 1,
            'supervisor-channel-operations' => 1,
            'supervisor-stock' => 1,
            'supervisor-shopee-orders' => 1,
            'supervisor-tiktok-orders' => 1,
            'supervisor-lazada-orders' => 1,
            'supervisor-tiktok-webhooks-operational' => 1,
            'supervisor-tiktok-webhooks-background' => 1,
            'supervisor-shopee-webhooks-operational' => 1,
            'supervisor-shopee-webhooks-background' => 1,
            'supervisor-lazada-webhooks-operational' => 1,
            'supervisor-lazada-webhooks-background' => 1,
        ] as $name => $workers) {
            $supervisor = config("horizon.defaults.{$name}");

            $this->assertSame('off', $supervisor['balance'] ?? null, "{$name} harus memakai pool bersama.");
            $this->assertSame($workers, $supervisor['minProcesses'] ?? null, "{$name} min worker tidak sesuai.");
            $this->assertSame($workers, $supervisor['maxProcesses'] ?? null, "{$name} max worker tidak sesuai.");
        }
    }

    public function test_channel_orders_and_operational_webhooks_are_isolated_from_background_work(): void
    {
        $expectedQueues = [
            'supervisor-shopee-orders' => [config('queue.names.shopee_orders', 'shopee-orders')],
            'supervisor-tiktok-orders' => [config('queue.names.tiktok_orders', 'tiktok-orders')],
            'supervisor-lazada-orders' => [config('queue.names.lazada_orders', 'lazada-orders')],
            'supervisor-tiktok-webhooks-operational' => [
                env('QUEUE_NAME_TIKTOK_PACKAGES', 'tiktok-packages'),
                env('QUEUE_NAME_TIKTOK_WEBHOOKS', 'tiktok-webhooks'),
            ],
            'supervisor-tiktok-webhooks-background' => [
                env('QUEUE_NAME_TIKTOK_AFTERSALES', 'tiktok-aftersales'),
                env('QUEUE_NAME_TIKTOK_CATALOG', 'tiktok-catalog'),
            ],
            'supervisor-shopee-webhooks-operational' => [
                env('QUEUE_NAME_SHOPEE_TRACKING', 'shopee-tracking'),
                env('QUEUE_NAME_SHOPEE_WEBHOOKS', 'shopee-webhooks'),
            ],
            'supervisor-shopee-webhooks-background' => [
                env('QUEUE_NAME_SHOPEE_AFTERSALES', 'shopee-aftersales'),
                env('QUEUE_NAME_SHOPEE_CATALOG', 'shopee-catalog'),
                env('QUEUE_NAME_WEBHOOK_DOWNLOADS', 'webhook-downloads'),
            ],
            'supervisor-lazada-webhooks-operational' => [
                env('QUEUE_NAME_LAZADA_FULFILLMENT', 'lazada-fulfillment'),
                env('QUEUE_NAME_LAZADA_WEBHOOKS', 'lazada-webhooks'),
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
        $production = array_replace_recursive(
            config('horizon.defaults', []),
            config('horizon.environments.production', []),
        );

        $workerMegabytes = collect($production)->sum(
            fn (array $supervisor): int => (int) ($supervisor['maxProcesses'] ?? 0)
                * (int) ($supervisor['memory'] ?? 0),
        );
        $withMasterMegabytes = $workerMegabytes + (int) config('horizon.memory_limit');

        $this->assertLessThanOrEqual(5734, $withMasterMegabytes);
    }
}
