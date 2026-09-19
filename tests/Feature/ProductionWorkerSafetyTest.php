<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductionWorkerSafetyTest extends TestCase
{
    public function test_long_queue_visibility_timeout_exceeds_worker_timeout(): void
    {
        $this->assertGreaterThan(
            1800,
            (int) config('queue.connections.redis-long.retry_after'),
            'Redis long queue retry_after harus lebih besar dari timeout worker terpanjang.',
        );
    }

    public function test_every_horizon_worker_has_visibility_timeout_headroom(): void
    {
        foreach (config('horizon.defaults', []) as $name => $supervisor) {
            $connection = (string) ($supervisor['connection'] ?? 'redis');
            $retryAfter = (int) config("queue.connections.{$connection}.retry_after");
            $timeout = (int) ($supervisor['timeout'] ?? 60);

            $this->assertGreaterThan(
                $timeout + 30,
                $retryAfter,
                "Supervisor {$name}: retry_after harus minimal 30 detik di atas timeout worker.",
            );
        }
    }

    public function test_heavy_workers_are_long_running_and_recycle_safely(): void
    {
        foreach ([
            '03-import-worker.yaml' => ['1860', '2400'],
            '03-export-worker.yaml' => ['960', '1500'],
            '03-catalog-export-worker.yaml' => ['720', '1200'],
            '03-pdf-export-worker.yaml' => ['1020', '1500'],
        ] as $manifest => [$grace, $deadline]) {
            $yaml = file_get_contents(base_path("k8s/production/{$manifest}"));

            $this->assertIsString($yaml);
            $this->assertStringContainsString('type: Recreate', $yaml, $manifest);
            $this->assertStringContainsString("terminationGracePeriodSeconds: {$grace}", $yaml, $manifest);
            $this->assertStringContainsString("progressDeadlineSeconds: {$deadline}", $yaml, $manifest);
            $this->assertStringContainsString('--max-jobs=100', $yaml, $manifest);
            $this->assertDoesNotMatchRegularExpression('/--max-jobs=1(?:\D|$)/', $yaml, $manifest);
            $this->assertStringContainsString('--max-time=', $yaml, $manifest);
        }
    }

    public function test_production_deploy_does_not_block_on_heavy_worker_drain(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/ci-cd-production.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString('rollout worker berat secara asynchronous', $workflow);
        $this->assertStringContainsString('--timeout=20s', $workflow);
        $this->assertStringContainsString('Menunggu rollout deployment/$deployment (maksimal 300 detik)', $workflow);
    }
}
