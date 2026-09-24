<?php

namespace Modules\Sales\Tests\Unit;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Modules\Sales\Jobs\PrepareLazadaShippingLabelJob;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Jobs\PrepareTikTokShippingLabelJob;
use Modules\Sales\Jobs\ProcessBulkShippingLabelItemJob;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Tests\TestCase;

class BulkShippingLabelDeduplicationTest extends TestCase
{
    public function test_label_ready_can_schedule_a_continuation_while_previous_job_finishes(): void
    {
        Queue::fake();
        $command = new ProcessBulkShippingLabelItemJob('batch-a', 'item-a', 'order-1', 'shopee');
        $this->assertTrue((new UniqueLock(app(Repository::class)))->acquire($command));
        $bus = \Mockery::mock(Bus::getFacadeRoot());
        Bus::swap($bus);
        $bus->shouldReceive('dispatchNow')->once()->andReturnUsing(function (): void {
            ProcessBulkShippingLabelItemJob::dispatch('batch-a', 'item-a', 'order-1', 'shopee');
        });
        $queuedJob = \Mockery::mock(Job::class);
        $queuedJob->shouldReceive('isReleased', 'hasFailed', 'isDeletedOrReleased')->andReturn(false);
        $queuedJob->shouldReceive('delete')->once();

        app(CallQueuedHandler::class)->call($queuedJob, ['command' => serialize($command)]);

        Queue::assertPushed(ProcessBulkShippingLabelItemJob::class, 1);
    }

    public function test_order_level_jobs_share_an_idempotency_key(): void
    {
        $first = new ProcessBulkShippingLabelItemJob('batch-a', 'item-a', 'order-1');
        $second = new ProcessBulkShippingLabelItemJob('batch-b', 'item-b', 'order-1');

        $this->assertSame('order:order-1', $first->uniqueId());
        $this->assertSame($first->uniqueId(), $second->uniqueId());
    }

    public function test_awb_retries_are_unique_per_order_and_attempt(): void
    {
        $first = new RequestChannelAwbJob('order-1', 0);
        $duplicate = new RequestChannelAwbJob('order-1', 0);
        $retry = new RequestChannelAwbJob('order-1', 1);

        $this->assertSame($first->uniqueId(), $duplicate->uniqueId());
        $this->assertNotSame($first->uniqueId(), $retry->uniqueId());
        $this->assertSame('label-awb', $first->queue);
    }

    public function test_prepare_jobs_are_unique_per_order_and_attempt(): void
    {
        $jobs = [
            new PrepareShopeeShippingLabelJob('order-1', 0),
            new PrepareTikTokShippingLabelJob('order-1', 0),
            new PrepareLazadaShippingLabelJob('order-1', 0),
        ];

        foreach ($jobs as $job) {
            $this->assertSame('order:order-1:attempt:0', $job->uniqueId());
        }
    }
}
