<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class KubernetesCapacityPreflightScriptTest extends TestCase
{
    public function test_transient_pending_pod_does_not_reject_rollout(): void
    {
        $process = $this->runPreflight([
            [
                'metadata' => ['name' => 'starting-worker'],
                'status' => [
                    'phase' => 'Pending',
                    'conditions' => [[
                        'type' => 'PodScheduled',
                        'status' => 'True',
                    ]],
                ],
            ],
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Pending sementara tidak memblokir rollout', $process->getOutput());
        $this->assertStringContainsString('Capacity preflight lulus', $process->getOutput());
    }

    public function test_unschedulable_pending_pod_rejects_rollout_with_reason(): void
    {
        $process = $this->runPreflight([
            [
                'metadata' => ['name' => 'worker-without-capacity'],
                'status' => [
                    'phase' => 'Pending',
                    'conditions' => [[
                        'type' => 'PodScheduled',
                        'status' => 'False',
                        'reason' => 'Unschedulable',
                        'message' => '0/1 nodes are available: 1 Insufficient memory.',
                    ]],
                ],
            ],
        ]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('worker-without-capacity', $process->getOutput());
        $this->assertStringContainsString('Insufficient memory', $process->getOutput());
    }

    private function runPreflight(array $pods): Process
    {
        $directory = sys_get_temp_dir().'/cilupbah-capacity-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $kubectl = $directory.'/kubectl';
        file_put_contents($kubectl, <<<'BASH'
#!/usr/bin/env bash
set -eu

case "${1:-} ${2:-}" in
  "get nodes")
    printf '%s\n' '{"items":[{"metadata":{"name":"node-1"},"status":{"conditions":[{"type":"Ready","status":"True"}]}}]}'
    ;;
  "get node")
    printf '32Gi'
    ;;
  "describe node")
    printf '%s\n' 'Allocated resources:' '  Resource  Requests  Limits' '  memory  10240Mi (31%)  20480Mi (62%)'
    ;;
  "get pods")
    printf '%s\n' "$PODS_JSON"
    ;;
  *)
    printf 'Unexpected kubectl invocation: %s\n' "$*" >&2
    exit 64
    ;;
esac
BASH);
        chmod($kubectl, 0700);

        $process = new Process(
            ['bash', base_path('scripts/kubernetes-capacity-preflight'), 'cilupbah', '2048', '90'],
            base_path(),
            [
                'PATH' => $directory.':'.getenv('PATH'),
                'PODS_JSON' => json_encode(['items' => $pods], JSON_THROW_ON_ERROR),
            ],
        );
        $process->run();

        unlink($kubectl);
        rmdir($directory);

        return $process;
    }
}
