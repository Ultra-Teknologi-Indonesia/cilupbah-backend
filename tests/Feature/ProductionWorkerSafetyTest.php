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

    public function test_production_deploy_allows_recreate_grace_period_and_reports_rollout_failures(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/ci-cd-production.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString('rollout worker berat secara asynchronous', $workflow);
        $this->assertStringContainsString('--timeout=20s', $workflow);
        $this->assertStringContainsString("jsonpath='{.spec.progressDeadlineSeconds}'", $workflow);
        $this->assertStringContainsString('rollout_timeout=$((progress_deadline + 120))', $workflow);
        $this->assertStringContainsString('--timeout=180s', $workflow);
        $this->assertStringContainsString('mengumpulkan diagnostik', $workflow);
    }

    public function test_app_rollout_has_startup_headroom_for_bootstrap(): void
    {
        $yaml = file_get_contents(base_path('k8s/production/02-app.yaml'));

        $this->assertIsString($yaml);
        $this->assertStringContainsString('progressDeadlineSeconds: 600', $yaml);
        $this->assertStringContainsString('startupProbe:', $yaml);
        $this->assertStringContainsString('failureThreshold: 60', $yaml);
    }

    public function test_production_deploy_uses_isolated_manifest_directory_and_serializes_runs(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/ci-cd-production.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString('group: production-deploy', $workflow);
        $this->assertStringContainsString('cancel-in-progress: false', $workflow);
        $this->assertStringContainsString('/tmp/cilupbah-k8s-${{ github.run_id }}-${{ github.run_attempt }}', $workflow);
        $this->assertStringContainsString('Manifest critical tidak ditemukan', $workflow);
        $this->assertStringNotContainsString('target: "/tmp/cilupbah-k8s"', $workflow);
    }

    public function test_production_workflow_keeps_the_version_selector(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/ci-cd-production.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString('name: CI/CD Pipeline (Production)', $workflow);
        $this->assertStringContainsString('type: choice', $workflow);
        $this->assertStringContainsString('options:', $workflow);
        $this->assertStringContainsString('- latest', $workflow);
        $this->assertStringContainsString('# BEGIN_VERSIONS', $workflow);
        $this->assertStringContainsString('# END_VERSIONS', $workflow);
    }

    public function test_shared_label_spool_init_is_constant_time(): void
    {
        foreach (['02-app.yaml', '04-horizon-labels.yaml'] as $manifest) {
            $yaml = file_get_contents(base_path("k8s/production/{$manifest}"));

            $this->assertIsString($yaml);
            $this->assertStringContainsString('chown root:33 /spool /spool/items;', $yaml, $manifest);
            $this->assertStringContainsString('chmod 2770 /spool /spool/items;', $yaml, $manifest);
            $this->assertStringNotContainsString('chown -R root:33 /spool', $yaml, $manifest);
            $this->assertStringNotContainsString('find /spool', $yaml, $manifest);
        }
    }

    public function test_legacy_recovery_worker_is_decommissioned(): void
    {
        $this->assertFileDoesNotExist(base_path('k8s/production/03-horizon-legacy-queue-recovery.yaml'));
        $this->assertArrayNotHasKey('redis-legacy', config('queue.connections'));
        $this->assertArrayNotHasKey('legacy-recovery', config('horizon.profiles'));

        $workflow = file_get_contents(base_path('.github/workflows/ci-cd-production.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString(
            'kubectl delete deployment cilupbah-horizon-legacy-queue-recovery',
            $workflow,
        );
    }

    public function test_operational_capabilities_are_deployed_in_separate_horizon_pools(): void
    {
        foreach ([
            '03-horizon-order-intake.yaml' => 'order-intake',
            '03-horizon-fulfillment.yaml' => 'fulfillment',
            '03-horizon-stock.yaml' => 'stock',
            '03-horizon-critical.yaml' => 'marketplace-ops',
            '04-horizon-labels-awb.yaml' => 'labels-awb',
            '04-horizon-labels.yaml' => 'labels-pdf',
        ] as $manifest => $profile) {
            $yaml = file_get_contents(base_path("k8s/production/{$manifest}"));

            $this->assertIsString($yaml);
            $this->assertStringContainsString('type: Recreate', $yaml, $manifest);
            $this->assertStringContainsString('name: HORIZON_PROFILE', $yaml, $manifest);
            $this->assertStringContainsString("value: \"{$profile}\"", $yaml, $manifest);
        }

        $workflow = file_get_contents(base_path('.github/workflows/ci-cd-production.yml'));

        $this->assertIsString($workflow);
        foreach ([
            'cilupbah-horizon-order-intake',
            'cilupbah-horizon-fulfillment',
            'cilupbah-horizon-stock',
        ] as $deployment) {
            $this->assertStringContainsString($deployment, $workflow);
        }
    }
}
