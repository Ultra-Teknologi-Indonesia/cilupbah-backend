<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class KubernetesManifestSanitizerTest extends TestCase
{
    public function test_it_removes_server_metadata_and_preserves_only_target_replica_count(): void
    {
        $input = json_encode([
            'apiVersion' => 'v1',
            'kind' => 'List',
            'metadata' => ['resourceVersion' => '0'],
            'items' => [
                [
                    'apiVersion' => 'apps/v1',
                    'kind' => 'Deployment',
                    'metadata' => [
                        'name' => 'cilupbah-app',
                        'resourceVersion' => '0',
                        'uid' => 'server-uid',
                        'generation' => 9,
                        'creationTimestamp' => null,
                        'managedFields' => [['manager' => 'kubectl']],
                    ],
                    'spec' => ['replicas' => 3],
                    'status' => ['availableReplicas' => 3],
                ],
                [
                    'apiVersion' => 'policy/v1',
                    'kind' => 'PodDisruptionBudget',
                    'metadata' => [
                        'name' => 'cilupbah-app',
                        'resourceVersion' => '0',
                    ],
                    'spec' => ['minAvailable' => 2],
                    'status' => ['currentHealthy' => 3],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $process = new Process([
            'bash',
            base_path('scripts/kubernetes-sanitize-manifest'),
            'cilupbah-app',
            '4',
        ]);
        $process->setInput($input);
        $process->mustRun();

        $output = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $deployment = $output['items'][0];
        $pdb = $output['items'][1];

        $this->assertArrayNotHasKey('resourceVersion', $output['metadata']);
        $this->assertSame(4, $deployment['spec']['replicas']);
        $this->assertArrayNotHasKey('resourceVersion', $deployment['metadata']);
        $this->assertArrayNotHasKey('uid', $deployment['metadata']);
        $this->assertArrayNotHasKey('generation', $deployment['metadata']);
        $this->assertArrayNotHasKey('creationTimestamp', $deployment['metadata']);
        $this->assertArrayNotHasKey('managedFields', $deployment['metadata']);
        $this->assertArrayNotHasKey('status', $deployment);
        $this->assertArrayNotHasKey('resourceVersion', $pdb['metadata']);
        $this->assertArrayNotHasKey('status', $pdb);
    }

    public function test_it_rejects_non_numeric_replica_count(): void
    {
        $process = new Process([
            'bash',
            base_path('scripts/kubernetes-sanitize-manifest'),
            'cilupbah-app',
            'invalid',
        ]);
        $process->setInput('{}');
        $process->run();

        $this->assertSame(64, $process->getExitCode());
        $this->assertStringContainsString('bilangan bulat', $process->getErrorOutput());
    }
}
