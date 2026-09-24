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
            $this->assertStringContainsString('type: RollingUpdate', $yaml, $manifest);
            $this->assertStringContainsString('maxUnavailable: 0', $yaml, $manifest);
            $this->assertStringContainsString('maxSurge: 1', $yaml, $manifest);
            $this->assertStringContainsString("terminationGracePeriodSeconds: {$grace}", $yaml, $manifest);
            $this->assertStringContainsString("progressDeadlineSeconds: {$deadline}", $yaml, $manifest);
            $this->assertMatchesRegularExpression('/--max-jobs=(?:[2-9][0-9]|[1-9][0-9]{2,})\b/', $yaml, $manifest);
            $this->assertStringContainsString('--max-time=', $yaml, $manifest);
        }
    }

    public function test_optional_keda_scaling_is_backlog_driven_and_never_scales_critical_pools_to_zero(): void
    {
        $yaml = file_get_contents(base_path('k8s/production/08-keda-autoscaling.yaml'));
        $background = file_get_contents(base_path('k8s/production/03-horizon.yaml'));
        $maintenance = file_get_contents(base_path('k8s/production/03-horizon-maintenance.yaml'));

        $this->assertIsString($yaml);
        $this->assertIsString($background);
        $this->assertIsString($maintenance);
        $this->assertStringContainsString('kind: ScaledObject', $yaml);
        $this->assertStringContainsString('name: cilupbah-horizon', $yaml);
        $this->assertStringContainsString('name: cilupbah-horizon-maintenance', $yaml);
        $this->assertStringContainsString('minReplicaCount: 1', $yaml);
        $this->assertStringContainsString('maxReplicaCount: 3', $yaml);
        $this->assertStringContainsString('pollingInterval: 5', $yaml);
        $this->assertStringContainsString('type: redis', $yaml);
        $this->assertStringContainsString('address: redis-horizon.cilupbah.svc.cluster.local:6379', $yaml);
        $this->assertStringContainsString('address: redis-long.cilupbah.svc.cluster.local:6379', $yaml);
        $this->assertStringContainsString('address: redis-finance.cilupbah.svc.cluster.local:6379', $yaml);
        $this->assertStringContainsString('listName: queues:default', $yaml);
        $this->assertStringContainsString('listName: queues:downloads', $yaml);
        $this->assertStringContainsString('cooldownPeriod: 2400', $yaml);
        $this->assertStringContainsString('terminationGracePeriodSeconds: 360', $background);
        $this->assertStringContainsString('terminationGracePeriodSeconds: 1860', $maintenance);

        foreach ([
            'cilupbah-horizon-order-intake',
            'cilupbah-horizon-fulfillment',
            'cilupbah-horizon-stock',
            'cilupbah-horizon-labels-awb',
            'cilupbah-horizon-labels',
        ] as $criticalDeployment) {
            $this->assertStringNotContainsString(
                "name: {$criticalDeployment}",
                $yaml,
                "{$criticalDeployment} tidak boleh scale-to-zero melalui KEDA.",
            );
        }
    }

    public function test_background_grace_period_is_nested_under_pod_template(): void
    {
        $yaml = file_get_contents(base_path('k8s/production/03-horizon.yaml'));

        $this->assertIsString($yaml);
        $this->assertMatchesRegularExpression(
            '/    spec:\n(?:      (?:priorityClassName: cilupbah-batch|#[^\n]*)\n)*      terminationGracePeriodSeconds: 360/',
            $yaml,
        );
        $this->assertStringNotContainsString(
            "spec:\n  replicas: 1\n  terminationGracePeriodSeconds:",
            $yaml,
        );
    }

    public function test_production_workflow_applies_keda_only_when_the_cluster_supports_it(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/ci-cd-production.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString('08-keda-autoscaling.yaml', $workflow);
        $this->assertStringContainsString('api-resources --api-group=keda.sh', $workflow);
        $this->assertStringContainsString('KEDA belum terpasang', $workflow);
    }

    public function test_production_deploy_allows_grace_period_and_reports_rollout_failures(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/ci-cd-production.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString('drain asynchronous untuk worker long-running', $workflow);
        $this->assertStringContainsString('--timeout=15s', $workflow);
        $this->assertStringContainsString("jsonpath='{.spec.progressDeadlineSeconds}'", $workflow);
        $this->assertStringContainsString('rollout_timeout=$((progress_deadline + 120))', $workflow);
        $this->assertStringContainsString('--timeout=180s', $workflow);
        $this->assertStringContainsString('mengumpulkan diagnostik', $workflow);
        $this->assertStringContainsString('cilupbah-horizon-maintenance', $workflow);
        $this->assertStringContainsString('--request-timeout=30s', $workflow);
        $this->assertStringNotContainsString('kubectl rollout restart', $workflow);
        $this->assertStringNotContainsString('for horizon in cilupbah-horizon cilupbah-horizon-critical', $workflow);
    }

    public function test_production_manifests_prioritize_online_work_over_recoverable_batches(): void
    {
        $priorities = file_get_contents(base_path('k8s/production/00-priority-classes.yaml'));
        $orderIntake = file_get_contents(base_path('k8s/production/03-horizon-order-intake.yaml'));
        $stock = file_get_contents(base_path('k8s/production/03-horizon-stock.yaml'));
        $export = file_get_contents(base_path('k8s/production/03-export-worker.yaml'));

        $this->assertIsString($priorities);
        $this->assertStringContainsString('name: cilupbah-platform-critical', $priorities);
        $this->assertStringContainsString('name: cilupbah-online-critical', $priorities);
        $this->assertStringContainsString('name: cilupbah-batch', $priorities);
        $this->assertStringContainsString('priorityClassName: cilupbah-online-critical', $orderIntake);
        $this->assertStringContainsString('priorityClassName: cilupbah-online-critical', $stock);
        $this->assertStringContainsString('priorityClassName: cilupbah-batch', $export);
    }

    public function test_app_rollout_has_startup_headroom_for_bootstrap(): void
    {
        $yaml = file_get_contents(base_path('k8s/production/02-app.yaml'));
        $autoscaling = file_get_contents(base_path('k8s/production/07-app-autoscaling.yaml'));

        $this->assertIsString($yaml);
        $this->assertIsString($autoscaling);
        $this->assertStringContainsString('progressDeadlineSeconds: 600', $yaml);
        $this->assertStringContainsString('startupProbe:', $yaml);
        $this->assertStringContainsString('failureThreshold: 60', $yaml);
        $this->assertStringContainsString('maxReplicas: 6', $autoscaling);
        $this->assertStringContainsString('minAvailable: 2', $autoscaling);
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
        $this->assertFileDoesNotExist(base_path('k8s/production/03-horizon-order-recovery.yaml'));
        $this->assertFileDoesNotExist(base_path('k8s/production/04-horizon-labels-prefetch.yaml'));
        $this->assertFileDoesNotExist(base_path('k8s/production/04-horizon-labels-archive.yaml'));
        $this->assertArrayNotHasKey('redis-legacy', config('queue.connections'));
        $this->assertArrayNotHasKey('legacy-recovery', config('horizon.profiles'));
        $this->assertArrayNotHasKey('order-recovery', config('horizon.profiles'));

        $workflow = file_get_contents(base_path('.github/workflows/ci-cd-production.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString(
            'kubectl delete deployment cilupbah-horizon-legacy-queue-recovery',
            $workflow,
        );
        $this->assertStringContainsString('cilupbah-horizon-labels-prefetch', $workflow);
        $this->assertStringContainsString('cilupbah-horizon-labels-archive', $workflow);
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
            $this->assertStringContainsString('type: RollingUpdate', $yaml, $manifest);
            $this->assertStringContainsString('maxUnavailable: 0', $yaml, $manifest);
            $this->assertStringContainsString('maxSurge: 1', $yaml, $manifest);
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

    public function test_cli_workers_use_jittered_entrypoint_and_do_not_advertise_fake_readiness(): void
    {
        $script = file_get_contents(base_path('docker/queue-worker.sh'));

        $this->assertIsString($script);
        $this->assertStringContainsString('QUEUE_WORKER_STARTUP_JITTER_MAX_SECONDS', $script);
        $this->assertStringContainsString('exec php artisan queue:work "$@"', $script);

        foreach ([
            '03-import-worker.yaml',
            '03-export-worker.yaml',
            '03-catalog-export-worker.yaml',
            '03-pdf-export-worker.yaml',
        ] as $manifest) {
            $yaml = file_get_contents(base_path("k8s/production/{$manifest}"));
            $this->assertStringContainsString('/usr/local/bin/queue-worker.sh', $yaml, $manifest);
            $this->assertStringNotContainsString('readinessProbe:', $yaml, $manifest);
            $this->assertStringNotContainsString('kill -0 1', $yaml, $manifest);
        }
    }

    public function test_redis_queue_consumers_block_instead_of_polling(): void
    {
        foreach (['redis', 'redis-channel-sync', 'redis-long', 'redis-finance'] as $connection) {
            $this->assertGreaterThan(
                0,
                (int) config("queue.connections.{$connection}.block_for"),
                "{$connection} harus memakai blocking pop agar burst queue tidak terlambat.",
            );
        }
    }

    public function test_durable_redis_pools_keep_aof_rewrite_headroom(): void
    {
        foreach ([
            '01-redis.yaml' => '"3gb"',
            '01-redis-long.yaml' => '"3gb"',
            '01-redis-horizon.yaml' => '"3gb"',
            '01-redis-finance.yaml' => '"1536mb"',
        ] as $manifest => $maxMemory) {
            $yaml = file_get_contents(base_path("k8s/production/{$manifest}"));

            $this->assertIsString($yaml);
            $this->assertStringContainsString('- --maxmemory', $yaml, $manifest);
            $this->assertStringContainsString("- {$maxMemory}", $yaml, $manifest);
            $this->assertStringContainsString('- --maxmemory-policy', $yaml, $manifest);
            $this->assertStringContainsString('- noeviction', $yaml, $manifest);
            $this->assertStringContainsString('- --activedefrag', $yaml, $manifest);
        }
    }
}
