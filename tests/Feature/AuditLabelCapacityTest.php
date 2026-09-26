<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AuditLabelCapacityTest extends TestCase
{
    public function test_baseline_passes_without_channel_requests(): void
    {
        Http::preventStrayRequests();
        $this->artisan('channel:audit-label-capacity --json')
            ->expectsOutputToContain('configuration_checks_passed')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_unsafe_concurrency_and_visibility_timeout_fail_audit(): void
    {
        config([
            'horizon.queue_health_supervisors.supervisor-label-render.maxProcesses' => 100,
            'horizon.queue_health_supervisors.supervisor-label-render.timeout' => 10000,
        ]);
        $this->artisan('channel:audit-label-capacity --json')
            ->expectsOutputToContain('review_required')->assertFailed();
    }
}
