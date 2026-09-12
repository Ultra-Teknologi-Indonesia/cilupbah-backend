<?php

namespace Modules\Product\Tests\Feature;

use Modules\Product\Jobs\RecomputeProductChannelValidationJob;
use Tests\TestCase;

class RecomputeProductChannelValidationJobTest extends TestCase
{
    public function test_same_product_uses_one_unique_validation_job(): void
    {
        $job = new RecomputeProductChannelValidationJob('019ff601-1b0d-71e7-b36a-927766ec3a77');

        $this->assertSame('019ff601-1b0d-71e7-b36a-927766ec3a77', $job->uniqueId());
        $this->assertSame(600, $job->uniqueFor);
        $this->assertSame(3, $job->tries);
    }
}
