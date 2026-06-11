<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guard anti-drift: SEMUA queue yang dipakai job wajib dilayani supervisor Horizon.
 * Queue tanpa supervisor = job menumpuk diam-diam di Redis tanpa pernah diproses
 * (pernah terjadi pada queue 'webhooks' — webhook outbound tidak pernah terkirim).
 */
class HorizonQueueCoverageTest extends TestCase
{
    /** Semua queue yang dilayani supervisor Horizon (defaults berlaku lintas environment). */
    private function servedQueues(): array
    {
        $served = [];
        foreach (config('horizon.defaults', []) as $supervisor) {
            $served = array_merge($served, (array) ($supervisor['queue'] ?? []));
        }

        return array_values(array_unique($served));
    }

    public function test_all_named_queues_are_served_by_horizon(): void
    {
        $served = $this->servedQueues();

        $used = array_values(config('queue.names', []));
        $used[] = 'default';
        $used[] = config('webhook.queue', 'webhooks');

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

            // onQueue(config('a.b')) / onQueue(config('a.b', 'fallback')) / onQueue('literal')
            if (! preg_match("/onQueue\((?:config\('([^']+)'(?:\s*,\s*'([^']+)')?\)|'([^']+)')\)/", $src, $m)) {
                continue; // tanpa onQueue → 'default' (sudah diuji di atas) atau di-set di dispatch site
            }

            $queue = $m[3] ?? null;
            if (! $queue) {
                $queue = config($m[1], $m[2] ?? null);
            }

            $this->assertNotNull($queue, "Queue untuk job {$file} tidak ter-resolve.");
            $this->assertContains(
                $queue,
                $served,
                basename($file) . " memakai queue '{$queue}' yang tidak dilayani supervisor Horizon."
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

    // Catatan: QUEUE_CONNECTION=redis di .env server TIDAK bisa diuji dari sini
    // (phpunit meng-override ke sync). Diverifikasi manual: .env staging = redis.
}
