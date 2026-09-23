<?php

namespace Tests\Feature;

use Tests\TestCase;

class HorizonQueueCoverageTest extends TestCase
{
    private function servedQueues(): array
    {
        $served = collect(config('horizon.defaults', []))
            ->pluck('queue')
            ->flatten()
            ->merge(config('queue.dedicated_queues', []))
            ->unique()
            ->values()
            ->all();

        sort($served);

        return $served;
    }

    public function test_all_named_queues_are_served_by_a_worker_pool(): void
    {
        $served = $this->servedQueues();
        $required = array_merge(
            array_values(config('queue.names', [])),
            [
                'default',
                config('webhook.queue', 'webhooks'),
                config('operations.stock_cutover_console.queue', 'stock-cutover'),
                config('operations.order_cutover_console.queue', 'order-cutover'),
            ],
        );

        foreach (array_unique($required) as $queue) {
            $this->assertContains(
                $queue,
                $served,
                "Queue '{$queue}' tidak dilayani pool worker mana pun.",
            );
        }
    }

    public function test_every_explicit_job_queue_is_served(): void
    {
        $served = $this->servedQueues();
        $jobFiles = array_merge(
            glob(base_path('Modules/*/app/Jobs/*.php')) ?: [],
            glob(base_path('app/Jobs/*.php')) ?: [],
        );

        foreach ($jobFiles as $file) {
            $source = file_get_contents($file);

            if (! preg_match_all("/onQueue\\((?:config\\('([^']+)'(?:\\s*,\\s*'([^']+)')?\\)|'([^']+)')\\)/", $source, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $queue = $match[3] ?? null;
                if (! $queue) {
                    $queue = config($match[1], $match[2] ?? null);
                }

                $this->assertNotNull($queue, "Queue {$file} tidak dapat di-resolve.");
                $this->assertContains($queue, $served, basename($file)." memakai queue '{$queue}' tanpa worker.");
            }
        }
    }

    public function test_pooled_supervisors_use_existing_redis_connections_and_recycle(): void
    {
        $connections = config('queue.connections', []);

        foreach (config('horizon.defaults', []) as $name => $supervisor) {
            $connection = $supervisor['connection'] ?? 'redis';

            $this->assertArrayHasKey($connection, $connections, "{$name}: koneksi {$connection} tidak ada.");
            $this->assertSame('redis', $connections[$connection]['driver'] ?? null, "{$name}: Horizon hanya mendukung Redis.");
            $this->assertSame('auto', $supervisor['balance'] ?? null, "{$name}: pool harus autoscale berdasarkan backlog.");
            $this->assertSame('size', $supervisor['autoScalingStrategy'] ?? null, "{$name}: autoscaler harus memakai ukuran antrean.");
            $this->assertSame(1, $supervisor['minProcesses'] ?? null, "{$name}: idle baseline harus satu worker.");
            $this->assertGreaterThanOrEqual(1, $supervisor['maxProcesses'] ?? 0, "{$name}: tidak memiliki kapasitas worker.");
            $this->assertGreaterThanOrEqual(300, $supervisor['maxTime'] ?? 0, "{$name}: worker harus recycle berkala.");
            $this->assertGreaterThanOrEqual(10, $supervisor['maxJobs'] ?? 0, "{$name}: worker harus recycle berdasarkan jumlah job.");
        }
    }

    public function test_awb_and_label_downloads_keep_marketplace_bulkheads(): void
    {
        foreach (['shopee', 'tiktok', 'lazada'] as $channel) {
            $request = config("horizon.defaults.supervisor-label-awb-request-{$channel}");
            $this->assertSame([
                config("queue.names.label_awb_request_{$channel}"),
            ], $request['queue'] ?? null);
            $this->assertSame(3, $request['tries'] ?? null);

            $poll = config("horizon.defaults.supervisor-label-awb-poll-{$channel}");
            $this->assertSame([
                config("queue.names.label_awb_poll_{$channel}"),
            ], $poll['queue'] ?? null);
            $this->assertSame(8, $poll['tries'] ?? null);

            $download = config("horizon.defaults.supervisor-label-download-{$channel}");
            $this->assertSame([
                config("queue.names.label_download_{$channel}"),
            ], $download['queue'] ?? null);
        }

        $this->assertNotSame(
            config('horizon.defaults.supervisor-label-awb-request-shopee.queue'),
            config('horizon.defaults.supervisor-label-awb-request-tiktok.queue'),
        );
    }

    public function test_profiles_are_disjoint_and_reduce_permanent_supervisor_count(): void
    {
        $profiles = config('horizon.profiles', []);
        $definitions = config('horizon.defaults', []);
        $names = array_merge(...array_values($profiles));

        $this->assertCount(count(array_unique($names)), $names, 'Supervisor tidak boleh dimiliki lebih dari satu pool.');
        $definitionNames = array_keys($definitions);
        sort($definitionNames);
        sort($names);
        $this->assertSame($definitionNames, $names);
        $this->assertCount(24, $definitions, 'Baseline production harus membatasi supervisor permanen menjadi 24.');
        $this->assertLessThanOrEqual(24, count($definitions));

        $queuesByProfile = [];
        foreach ($profiles as $profile => $profileNames) {
            $queuesByProfile[$profile] = collect($profileNames)
                ->flatMap(fn (string $name): array => $definitions[$name]['queue'])
                ->unique()
                ->values()
                ->all();
        }

        foreach ($queuesByProfile as $profile => $queues) {
            foreach ($queuesByProfile as $otherProfile => $otherQueues) {
                if ($profile >= $otherProfile) {
                    continue;
                }

                $this->assertSame([], array_values(array_intersect($queues, $otherQueues)));
            }
        }
    }

    public function test_manifest_memory_requests_cover_measured_idle_process_budget(): void
    {
        $definitions = config('horizon.defaults', []);
        $processBudget = (int) config('horizon.resident_process_budget_mb');
        $requests = config('horizon.profile_memory_requests_mb', []);

        foreach (config('horizon.profiles', []) as $profile => $names) {
            $minimumProcesses = count($names) + 1;
            $maximumWorkerMemory = 0;
            foreach ($names as $name) {
                $minimumProcesses += (int) $definitions[$name]['minProcesses'];
                $maximumWorkerMemory += (int) $definitions[$name]['maxProcesses']
                    * (int) $definitions[$name]['memory'];
            }

            $this->assertGreaterThanOrEqual(
                $minimumProcesses * $processBudget,
                (int) ($requests[$profile] ?? 0),
                "Request memori {$profile} tidak menutup master, supervisor, dan worker idle.",
            );
            $this->assertGreaterThanOrEqual(
                $maximumWorkerMemory + ((count($names) + 1) * $processBudget),
                (int) config("horizon.profile_memory_limits_mb.{$profile}"),
                "Limit memori {$profile} lebih kecil dari burst worker yang dikonfigurasi.",
            );
        }

        foreach ([
            'order-intake' => ['03-horizon-order-intake.yaml', '768Mi', '2560Mi'],
            'fulfillment' => ['03-horizon-fulfillment.yaml', '512Mi', '1536Mi'],
            'stock' => ['03-horizon-stock.yaml', '768Mi', '2560Mi'],
            'marketplace-ops' => ['03-horizon-critical.yaml', '768Mi', '2560Mi'],
            'background' => ['03-horizon.yaml', '1Gi', '2Gi'],
            'labels-pdf' => ['04-horizon-labels.yaml', '1536Mi', '5Gi'],
            'labels-awb' => ['04-horizon-labels-awb.yaml', '2Gi', '3Gi'],
            'maintenance' => ['03-horizon-maintenance.yaml', '768Mi', '2Gi'],
        ] as $profile => [$manifest, $request, $limit]) {
            $yaml = file_get_contents(base_path("k8s/production/{$manifest}"));
            $this->assertStringContainsString("memory: \"{$request}\"", $yaml, $manifest);
            $this->assertStringContainsString("memory: \"{$limit}\"", $yaml, $manifest);
            $this->assertSame(
                $this->megabytes($limit),
                (int) config("horizon.profile_memory_limits_mb.{$profile}"),
                "Limit {$profile} harus selaras dengan manifest.",
            );
        }
    }

    private function megabytes(string $value): int
    {
        return match (true) {
            str_ends_with($value, 'Gi') => (int) $value * 1024,
            str_ends_with($value, 'Mi') => (int) $value,
            default => throw new \InvalidArgumentException("Satuan memori {$value} tidak didukung."),
        };
    }
}
