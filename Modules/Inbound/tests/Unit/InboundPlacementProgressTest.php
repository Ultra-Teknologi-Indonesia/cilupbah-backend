<?php

namespace Modules\Inbound\Tests\Unit;

use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Modules\Inbound\Support\InboundPlacementProgress;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InboundPlacementProgressTest extends TestCase
{
    public static function placementCases(): array
    {
        return [
            'belum diterima' => [0, 0, InboundPlacementProgress::STATUS_NOT_STARTED],
            'diterima belum ditempatkan' => [10, 0, InboundPlacementProgress::STATUS_NOT_STARTED],
            'sebagian ditempatkan' => [10, 4, InboundPlacementProgress::STATUS_PARTIAL],
            'selesai ditempatkan' => [10, 10, InboundPlacementProgress::STATUS_COMPLETED],
        ];
    }

    #[DataProvider('placementCases')]
    public function test_status_penempatan_diturunkan_dari_qty(
        int $received,
        int $putaway,
        string $expectedStatus,
    ): void {
        $summary = InboundPlacementProgress::summarize([
            (object) [
                'expected_qty' => $received,
                'received_qty' => $received,
                'putaway_qty' => $putaway,
                'reserved_qty' => max(0, $received - $putaway),
            ],
        ], Inbound::STATUS_COMPLETED);

        $this->assertSame($expectedStatus, $summary['status']);
        $this->assertSame(max(0, $received - $putaway), $summary['pending_qty']);
    }

    public function test_status_penerimaan_cancelled_mengalahkan_progress_qty(): void
    {
        $summary = InboundPlacementProgress::summarize([
            (object) ['received_qty' => 10, 'putaway_qty' => 10, 'reserved_qty' => 0],
        ], Inbound::STATUS_CANCELLED);

        $this->assertSame(InboundPlacementProgress::STATUS_CANCELLED, $summary['status']);
    }

    public function test_retur_yang_belum_diterima_tidak_memiliki_qty_penempatan(): void
    {
        $summary = InboundPlacementProgress::summarize([
            (object) [
                'expected_qty' => 3,
                'received_qty' => 0,
                'putaway_qty' => 1,
                'reserved_qty' => 2,
            ],
        ], Inbound::STATUS_RECEIVED);

        $this->assertSame(0, $summary['received_qty']);
        $this->assertSame(0, $summary['pending_qty']);
        $this->assertSame(InboundPlacementProgress::STATUS_NOT_STARTED, $summary['status']);
    }

    public function test_decorator_mempertahankan_status_lama_dan_menambah_kontrak_baru(): void
    {
        $inbound = new Inbound([
            'type' => Inbound::TYPE_PURCHASE_ORDER,
            'status' => Inbound::STATUS_COMPLETED,
        ]);
        $inbound->setRelation('items', collect([
            new InboundItem([
                'received_qty' => 8,
                'putaway_qty' => 3,
                'reserved_qty' => 5,
            ]),
        ]));

        $payload = InboundPlacementProgress::decorate($inbound)->toArray();

        $this->assertSame(Inbound::STATUS_COMPLETED, $payload['status']);
        $this->assertSame(Inbound::STATUS_COMPLETED, $payload['receiving_status']);
        $this->assertSame(InboundPlacementProgress::STATUS_PARTIAL, $payload['placement_status']);
        $this->assertSame(5, $payload['placement_summary']['pending_qty']);
        $this->assertTrue($payload['placement_summary']['is_consistent']);
    }
}
