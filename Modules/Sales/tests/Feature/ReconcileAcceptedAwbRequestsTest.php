<?php

declare(strict_types=1);

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Support\ChannelOperationLedger;
use Tests\TestCase;

final class ReconcileAcceptedAwbRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_schedules_a_read_only_verification_for_an_old_accepted_awb_request(): void
    {
        Queue::fake();

        $order = SalesOrder::factory()->create();
        $claim = ChannelOperationLedger::claim($order, 'request_awb');
        ChannelOperationLedger::markAccepted($claim['attempt']);
        $claim['attempt']->fresh()->forceFill(['updated_at' => now()->subMinutes(6)])->save();

        $this->artisan('shipping-labels:reconcile-accepted-awb')
            ->expectsOutput('Verifikasi AWB baca-saja dijadwalkan: 1 order.')
            ->assertSuccessful();

        Queue::assertPushed(
            RequestChannelAwbJob::class,
            fn (RequestChannelAwbJob $job): bool => $job->orderId === $order->id
                && $job->trackingAttempt === 1
                && ! $job->requestReadyToShip
                && $job->verificationOnly,
        );
    }
}
