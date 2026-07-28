<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\LazadaToInternalOrderMapper;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'repacked' => ['repacked', 'READY_TO_SHIP'],
            'returned' => ['returned', 'RETURNED'],
            'failed_delivery' => ['failed_delivery', 'SHIPPED'],
            'shipped_back' => ['shipped_back', 'RETURNED'],
            'shipped_back_success' => ['shipped_back_success', 'RETURNED'],
            'shipped_back_failed' => ['shipped_back_failed', 'SHIPPED'],
            'lost_by_3pl' => ['lost_by_3pl', 'SHIPPED'],
            'damaged_by_3pl' => ['damaged_by_3pl', 'SHIPPED'],
        ];
    }

    #[DataProvider('statusProvider')]
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
