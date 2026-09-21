<?php

namespace Tests\Unit;

use App\Support\ReceivedLineAllocator;
use PHPUnit\Framework\TestCase;

class ReceivedLineAllocatorTest extends TestCase
{

    private function bucket(array $qtys): array
    {
        return array_map(fn (int $qty) => ['received_qty' => $qty], array_values($qtys));
    }

    public function test_each_line_gets_its_own_entry_for_same_item_id(): void
    {
        $allocator = new ReceivedLineAllocator;
        $bucket = $this->bucket([99, 100]);

        $this->assertSame(99, $allocator->take($bucket, 'item-1', 99)['received_qty']);
        $this->assertSame(100, $allocator->take($bucket, 'item-1', 100)['received_qty']);
    }

    public function test_out_of_order_payload_still_fits_each_line(): void
    {
        $allocator = new ReceivedLineAllocator;
        $bucket = $this->bucket([100, 99]);

        $this->assertSame(99, $allocator->take($bucket, 'item-1', 99)['received_qty']);
        $this->assertSame(100, $allocator->take($bucket, 'item-1', 100)['received_qty']);
    }

    public function test_empty_bucket_keeps_line_qty_as_fallback(): void
    {
        $allocator = new ReceivedLineAllocator;

        $this->assertNull($allocator->take([], 'item-1', 10));
    }

    public function test_qty_over_line_keeps_user_value_for_validation_error(): void
    {
        $allocator = new ReceivedLineAllocator;
        $bucket = $this->bucket([100, 100]);

        $first = $allocator->take($bucket, 'item-1', 99);

        $this->assertSame(100, $first['received_qty']);
    }

    public function test_extra_lines_reuse_last_entry_like_before(): void
    {
        $allocator = new ReceivedLineAllocator;
        $bucket = $this->bucket([50]);

        $this->assertSame(50, $allocator->take($bucket, 'item-1', 50)['received_qty']);
        $this->assertSame(50, $allocator->take($bucket, 'item-1', 50)['received_qty']);
    }

    public function test_different_item_ids_do_not_share_entries(): void
    {
        $allocator = new ReceivedLineAllocator;
        $bucketA = $this->bucket([10]);
        $bucketB = $this->bucket([20]);

        $this->assertSame(10, $allocator->take($bucketA, 'item-a', 10)['received_qty']);
        $this->assertSame(20, $allocator->take($bucketB, 'item-b', 20)['received_qty']);
    }
}
