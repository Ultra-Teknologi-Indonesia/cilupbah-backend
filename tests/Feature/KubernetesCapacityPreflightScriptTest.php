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

    public function test_high_requested_memory_can_pass_when_actual_headroom_is_healthy(): void
    {
        $process = $this->runPreflight([], 30000, 2048, '12000Mi', 'False');

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('actual headroom', $process->getOutput());
    }

    public function test_high_requested_memory_remains_blocked_when_node_is_under_pressure(): void
    {
        $process = $this->runPreflight([], 30000, 2048, '12000Mi', 'True');

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('actual headroom atau MemoryPressure', $process->getOutput());
    }

    private function runPreflight(
        array $pods,
        int $requestedMi = 10240,
        int $surgeMi = 2048,
        string $usage = '12000Mi',
        string $pressure = 'False',
    ): Process {
        $directory = sys_get_temp_dir().'/cilupbah-capacity-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $kubectl = $directory.'/kubectl';
        file_put_contents($kubectl, <<<'BASH'
#!/usr/bin/env bash
set -eu

case "$*" in
  "get nodes -o json")
    printf '%s\n' '{"items":[{"metadata":{"name":"node-1"},"status":{"conditions":[{"type":"Ready","status":"True"}]}}]}'
    ;;
  "get node node-1 -o jsonpath="*)
    if [[ "$*" == *"allocatable.memory"* ]]; then
      printf '32Gi'
    else
      printf '%s' "${NODE_PRESSURE}"
    fi
    ;;
  "describe node node-1")
    printf '%s\n' 'Allocated resources:' '  Resource  Requests  Limits' "  memory  ${REQUESTED_MI}Mi (31%)  20480Mi (62%)"
    ;;
  "get pods -n cilupbah --field-selector=status.phase=Pending -o json")
    printf '%s\n' "$PODS_JSON"
    ;;
  "top node node-1 --no-headers")
    printf 'node-1 1000m 5%% %s 40%%\n' "${NODE_USAGE}"
    ;;
  *)
    printf 'Unexpected kubectl invocation: %s\n' "$*" >&2
    exit 64
    ;;
esac
BASH);
        chmod($kubectl, 0700);

        $process = new Process(
            ['bash', base_path('scripts/kubernetes-capacity-preflight'), 'cilupbah', (string) $surgeMi, '90'],
            base_path(),
            [
                'PATH' => $directory.':'.getenv('PATH'),
                'PODS_JSON' => json_encode(['items' => $pods], JSON_THROW_ON_ERROR),
                'NODE_USAGE' => $usage,
                'NODE_PRESSURE' => $pressure,
                'REQUESTED_MI' => (string) $requestedMi,
            ],
        );
        $process->run();

        unlink($kubectl);
        rmdir($directory);

        return $process;
    }
}
