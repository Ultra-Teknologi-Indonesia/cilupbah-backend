<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Sales\Jobs\AdminAlertJob;
use Modules\Sales\Jobs\ProcessChannelReturnJob;
use Modules\Sales\Jobs\SyncReturnDetailJob;
use Modules\Sales\Jobs\SyncReturnTrackingJob;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\SalesReturnService;
use Tests\TestCase;

class ProcessChannelReturnJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_tracking_and_detail_when_return_created(): void
    {
        Queue::fake();

        $return = new SalesReturn();
        $return->id = 'ret-uuid-1';

        $service = Mockery::mock(SalesReturnService::class);
        $service->shouldReceive('createFromChannel')->once()->andReturn($return);

        (new ProcessChannelReturnJob([
            'source' => 'shopee',
            'channel_order_id' => 'SO-1',
            'channel_return_id' => 'RET-1',
        ]))->handle($service);

        Queue::assertPushed(SyncReturnTrackingJob::class, fn ($j) => $j->salesReturnId === 'ret-uuid-1');
        Queue::assertPushed(SyncReturnDetailJob::class, fn ($j) => $j->salesReturnId === 'ret-uuid-1');
    }

    public function test_no_followup_jobs_when_creation_is_skipped(): void
    {
        Queue::fake();

        $service = Mockery::mock(SalesReturnService::class);
        $service->shouldReceive('createFromChannel')->once()->andReturn(null);

        (new ProcessChannelReturnJob([
            'source' => 'shopee',
            'channel_order_id' => 'SO-1',
        ]))->handle($service);

        Queue::assertNotPushed(SyncReturnTrackingJob::class);
        Queue::assertNotPushed(SyncReturnDetailJob::class);
    }

    public function test_permanent_failure_raises_admin_alert(): void
    {
        Queue::fake();

        (new ProcessChannelReturnJob([
            'source' => 'lazada',
            'channel_order_id' => 'SO-9',
            'channel_return_id' => 'RO-9',
        ]))->failed(new \RuntimeException('boom'));

        Queue::assertPushed(AdminAlertJob::class, fn ($j) => str_contains($j->subject, 'Retur/refund channel gagal')
            && ($j->context['source'] ?? null) === 'lazada'
            && ($j->context['channel_order_id'] ?? null) === 'SO-9');
    }
}
