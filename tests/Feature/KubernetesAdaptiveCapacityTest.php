<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class KubernetesAdaptiveCapacityTest extends TestCase
{
    public function test_all_operational_pools_remain_warm_and_bounded(): void
    {
        $objects = $this->productionObjects();
        $scaledObjects = collect($objects)
            ->where('kind', 'ScaledObject')
            ->keyBy('metadata.name');

        $expected = [
            'cilupbah-horizon-background' => ['cilupbah-horizon', 2],
            'cilupbah-horizon-maintenance' => ['cilupbah-horizon-maintenance', 1],
            'cilupbah-horizon-order-intake' => ['cilupbah-horizon-order-intake', 2],
            'cilupbah-horizon-fulfillment' => ['cilupbah-horizon-fulfillment', 2],
            'cilupbah-horizon-stock' => ['cilupbah-horizon-stock', 2],
            'cilupbah-horizon-marketplace-ops' => ['cilupbah-horizon-critical', 2],
            'cilupbah-horizon-labels-awb' => ['cilupbah-horizon-labels-awb', 1],
            'cilupbah-horizon-labels' => ['cilupbah-horizon-labels', 1],
        ];

        foreach ($expected as $name => [$target, $maximum]) {
            $scaledObject = $scaledObjects->get($name);

            $this->assertNotNull($scaledObject, "ScaledObject {$name} tidak ditemukan.");
            $this->assertSame($target, data_get($scaledObject, 'spec.scaleTargetRef.name'));
            $this->assertSame(1, data_get($scaledObject, 'spec.minReplicaCount'));
            $this->assertSame($maximum, data_get($scaledObject, 'spec.maxReplicaCount'));
        }
    }

    public function test_critical_scalers_watch_every_served_queue_on_the_correct_redis(): void
    {
        $objects = $this->productionObjects();
        $scaledObjects = collect($objects)
            ->where('kind', 'ScaledObject')
            ->keyBy('metadata.name');

        $expected = [
            'cilupbah-horizon-order-intake' => [
                'cilupbah_superapp_horizon:' => [
                    'orders', 'shopee-orders', 'tiktok-orders', 'lazada-orders',
                    'channel-order-refresh', 'channel-sync', 'channel-order-recovery', 'product',
                ],
            ],
            'cilupbah-horizon-stock' => [
                'cilupbah_superapp_horizon:' => [
                    'stock-sync', 'stock-critical', 'warehouse-safety', 'channel-stock-critical',
                    'channel-stock-outbox', 'stock-default', 'channel-stock', 'channel-stock-normal',
                ],
            ],
            'cilupbah-horizon-labels-awb' => [
                'cilupbah-superapp-database-' => [
                    'label-awb', 'label-awb-request-shopee', 'label-awb-request-tiktok',
                    'label-awb-request-lazada', 'label-awb-poll-shopee',
                    'label-awb-poll-tiktok', 'label-awb-poll-lazada',
                ],
            ],
            'cilupbah-horizon-labels' => [
                'cilupbah-superapp-database-' => [
                    'labels', 'label-merge', 'label-download-shopee', 'label-download-tiktok',
                    'label-download-lazada', 'label-prefetch', 'label-archive',
                ],
            ],
        ];

        foreach ($expected as $name => $redisQueues) {
            $triggers = collect(data_get($scaledObjects->get($name), 'spec.triggers', []));

            foreach ($redisQueues as $prefix => $queues) {
                foreach ($queues as $queue) {
                    $this->assertTrue(
                        $triggers->contains(fn (array $trigger): bool => data_get($trigger, 'metadata.address') === 'redis.cilupbah.svc.cluster.local:6379'
                            && data_get($trigger, 'metadata.listName') === "{$prefix}queues:{$queue}"),
                        "{$name} tidak memantau Redis runtime/{$prefix}queues:{$queue}.",
                    );
                }
            }
        }
    }

    public function test_every_redis_scaler_uses_the_runtime_service_and_an_explicit_laravel_prefix(): void
    {
        $triggers = collect($this->productionObjects())
            ->where('kind', 'ScaledObject')
            ->flatMap(fn (array $object): array => data_get($object, 'spec.triggers', []));

        $this->assertNotEmpty($triggers);

        foreach ($triggers as $trigger) {
            $this->assertSame('redis.cilupbah.svc.cluster.local:6379', data_get($trigger, 'metadata.address'));
            $this->assertMatchesRegularExpression(
                '/^cilupbah(?:_superapp_horizon:|-superapp-database-)queues:/',
                (string) data_get($trigger, 'metadata.listName'),
            );
        }
    }

    public function test_maximum_replica_requests_leave_twenty_percent_node_memory_reserve(): void
    {
        $objects = $this->productionObjects();
        $maximumReplicas = [];

        foreach ($objects as $object) {
            if (($object['kind'] ?? null) === 'ScaledObject') {
                $maximumReplicas[(string) data_get($object, 'spec.scaleTargetRef.name')] =
                    (int) data_get($object, 'spec.maxReplicaCount');
            }

            if (($object['kind'] ?? null) === 'HorizontalPodAutoscaler') {
                $maximumReplicas[(string) data_get($object, 'spec.scaleTargetRef.name')] =
                    (int) data_get($object, 'spec.maxReplicas');
            }
        }

        $totalMi = collect($objects)
            ->where('kind', 'Deployment')
            ->sum(function (array $deployment) use ($maximumReplicas): int {
                $name = (string) data_get($deployment, 'metadata.name');
                $replicas = $maximumReplicas[$name] ?? (int) data_get($deployment, 'spec.replicas', 1);
                $containers = data_get($deployment, 'spec.template.spec.containers', []);
                $initContainers = data_get($deployment, 'spec.template.spec.initContainers', []);
                $containerMi = collect($containers)->sum(
                    fn (array $container): int => $this->memoryMi(data_get($container, 'resources.requests.memory')),
                );
                $initMi = collect($initContainers)->max(
                    fn (array $container): int => $this->memoryMi(data_get($container, 'resources.requests.memory')),
                ) ?? 0;

                return max($containerMi, $initMi) * $replicas;
            });

        $this->assertLessThanOrEqual(23_040, $totalMi);
    }

    public function test_unused_dedicated_redis_targets_do_not_reserve_node_memory(): void
    {
        $deployments = collect($this->productionObjects())
            ->where('kind', 'Deployment')
            ->keyBy('metadata.name');

        foreach ([
            'cilupbah-redis-cache',
            'cilupbah-redis-finance',
            'cilupbah-redis-horizon',
            'cilupbah-redis-long',
        ] as $name) {
            $this->assertSame(0, data_get($deployments->get($name), 'spec.replicas'), "{$name} harus cold standby.");
        }
    }

    public function test_durable_batch_workloads_never_use_rollout_surge_memory(): void
    {
        $deployments = collect($this->productionObjects())
            ->where('kind', 'Deployment')
            ->keyBy('metadata.name');

        foreach ([
            'cilupbah-scheduler',
            'cilupbah-horizon',
            'cilupbah-horizon-maintenance',
            'cilupbah-import-worker',
            'cilupbah-export-worker',
            'cilupbah-catalog-export-worker',
            'cilupbah-pdf-export-worker',
        ] as $name) {
            $deployment = $deployments->get($name);
            $this->assertSame(0, data_get($deployment, 'spec.strategy.rollingUpdate.maxSurge'), $name);
            $this->assertSame(1, data_get($deployment, 'spec.strategy.rollingUpdate.maxUnavailable'), $name);
        }
    }

    private function productionObjects(): array
    {
        $objects = [];

        foreach (glob(base_path('k8s/production/*.yaml')) ?: [] as $path) {
            $documents = preg_split('/^---\s*$/m', (string) file_get_contents($path)) ?: [];

            foreach ($documents as $document) {
                $parsed = Yaml::parse(trim($document));
                if (is_array($parsed) && isset($parsed['kind'], $parsed['metadata']['name'])) {
                    $objects[] = $parsed;
                }
            }
        }

        return $objects;
    }

    private function memoryMi(mixed $value): int
    {
        if (! is_string($value) || $value === '') {
            return 0;
        }

        return match (true) {
            str_ends_with($value, 'Gi') => (int) $value * 1024,
            str_ends_with($value, 'Mi') => (int) $value,
            str_ends_with($value, 'Ki') => (int) ceil(((int) $value) / 1024),
            default => throw new \InvalidArgumentException("Satuan memori {$value} tidak didukung."),
        };
    }
}
