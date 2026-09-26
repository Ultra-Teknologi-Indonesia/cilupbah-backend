<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
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

    public function test_native_pdf_processes_are_included_in_the_budget(): void
    {
        Artisan::call('channel:audit-label-capacity', ['--json' => true]);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(512, $result['profiles']['labels-pdf']['mass_pdf_native_budget_mb']);
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
