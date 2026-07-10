<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\LazadaToInternalOrderMapper;
use Tests\TestCase;

class LazadaToInternalOrderMapperStatusTest extends TestCase
{
    private function mapWithStatus(string $status): array
    {
        $mapper = new LazadaToInternalOrderMapper();

        return $mapper->map([
            'order_id' => 900123,
            'statuses' => [$status],
            'price' => '100000.00',
            'created_at' => '2026-06-10 09:00:00 +0700',
        ], [], 'LZ-100');
    }

    public static function statusProvider(): array
    {
        return [
            'repacked masih menunggu pack ulang' => ['repacked', 'AWAITING_SHIPMENT'],
            'returned tetap DELIVERED (detail di SalesReturn)' => ['returned', 'DELIVERED'],
            'failed_delivery masih in transit' => ['failed_delivery', 'IN_TRANSIT'],
            'shipped_back masih in transit' => ['shipped_back', 'IN_TRANSIT'],
            'shipped_back_success masih in transit' => ['shipped_back_success', 'IN_TRANSIT'],
            'shipped_back_failed masih in transit' => ['shipped_back_failed', 'IN_TRANSIT'],
            'lost_by_3pl masih in transit' => ['lost_by_3pl', 'IN_TRANSIT'],
            'damaged_by_3pl masih in transit' => ['damaged_by_3pl', 'IN_TRANSIT'],
        ];
    }

    public function test_new_statuses_no_longer_fall_back_to_unpaid(string $lazadaStatus, string $expectedChannelStatus): void
    {
        $internal = $this->mapWithStatus($lazadaStatus);

        $this->assertSame($expectedChannelStatus, $internal['channel_status']);
        $this->assertNotSame('UNPAID', $internal['channel_status']);
    }

    public function test_reverse_logistics_statuses_are_not_treated_as_cancelled(): void
    {
        foreach (['returned', 'failed_delivery', 'shipped_back', 'lost_by_3pl', 'damaged_by_3pl'] as $status) {
            $internal = $this->mapWithStatus($status);
            $this->assertFalse($internal['is_canceled'], "status {$status} tidak boleh dianggap CANCELLED");
        }
    }
}
