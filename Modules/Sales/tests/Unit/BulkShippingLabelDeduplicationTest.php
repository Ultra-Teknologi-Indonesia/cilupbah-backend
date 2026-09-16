<?php

namespace Modules\Sales\Tests\Unit;

use Modules\Sales\Jobs\PrepareLazadaShippingLabelJob;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Jobs\PrepareTikTokShippingLabelJob;
use Modules\Sales\Jobs\ProcessBulkShippingLabelItemJob;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Tests\TestCase;

class BulkShippingLabelDeduplicationTest extends TestCase
{
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
