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
                'redis-horizon.cilupbah.svc.cluster.local:6379' => [
                    'orders', 'shopee-orders', 'tiktok-orders', 'lazada-orders',
                    'channel-order-refresh', 'channel-sync', 'channel-order-recovery', 'product',
                ],
            ],
            'cilupbah-horizon-stock' => [
                'redis-horizon.cilupbah.svc.cluster.local:6379' => [
                    'stock-sync', 'stock-critical', 'warehouse-safety', 'channel-stock-critical',
                    'channel-stock-outbox', 'stock-default', 'channel-stock', 'channel-stock-normal',
                ],
            ],
            'cilupbah-horizon-labels-awb' => [
                'redis-long.cilupbah.svc.cluster.local:6379' => [
                    'label-awb', 'label-awb-request-shopee', 'label-awb-request-tiktok',
                    'label-awb-request-lazada', 'label-awb-poll-shopee',
                    'label-awb-poll-tiktok', 'label-awb-poll-lazada',
                ],
            ],
            'cilupbah-horizon-labels' => [
                'redis-long.cilupbah.svc.cluster.local:6379' => [
                    'labels', 'label-merge', 'label-download-shopee', 'label-download-tiktok',
                    'label-download-lazada', 'label-prefetch', 'label-archive',
                ],
            ],
        ];

        foreach ($expected as $name => $redisQueues) {
            $triggers = collect(data_get($scaledObjects->get($name), 'spec.triggers', []));

            foreach ($redisQueues as $address => $queues) {
                foreach ($queues as $queue) {
                    $this->assertTrue(
                        $triggers->contains(fn (array $trigger): bool => data_get($trigger, 'metadata.address') === $address
                            && data_get($trigger, 'metadata.listName') === "queues:{$queue}"),
                        "{$name} tidak memantau {$address}/queues:{$queue}.",
                    );
                }
            }
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

        $this->assertLessThanOrEqual(25_600, $totalMi);
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
